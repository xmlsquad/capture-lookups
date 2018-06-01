<?php

namespace AppBundle\Service;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Yaml\Yaml;

class GoogleApiService {

    /** @var \Google_Client  */
    private $client;

    /**
     * Parsed mapping.yml
     *
     * @var mixed
     */
    private $mapping;

    /** @var string  */
    private $projectDir;

    /**
     * GoogleApiService constructor.
     * @param string $credentialsFile
     * @param string $mappingFile
     * @throws \Google_Exception
     */
    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;

    }

    protected function getClient(?string $credentialsFile = null): \Google_Client {

        if (null === $this->client) {
            if (null === $credentialsFile) {
                $credentialsFile = $this->projectDir.DIRECTORY_SEPARATOR.'credentials.json';
            }

            if (!is_readable($credentialsFile)) {
                throw new \Exception(sprintf('The credentials file doesn\'t exist: %s.', $credentialsFile));
            }

            // Set up the API client
            $client = new \Google_Client();
            $client->setAuthConfig($credentialsFile);
            $client->setApplicationName('capture-lookups');
            $client->setScopes([
                \Google_Service_Sheets::SPREADSHEETS_READONLY
            ]);
            $client->setAccessType('offline');

            $this->client = $client;
        }

        return $this->client;
    }

    /**
     * A helper method allowing to use different credentials during the lifecycle of the application
     *
     * @param string $credentialsFile
     * @return GoogleApiService
     * @throws \Exception
     */
    public function setCredentials(string $credentialsFile): GoogleApiService {
        $this->client = null;
        $this->getClient($credentialsFile);
        return $this;
    }

    /**
     * Returns the array resulting from mapping.yml
     *
     * @return array
     */
    public function getMapping() {

        if (null === $this->mapping) {
            $mappingFile = $this->projectDir. DIRECTORY_SEPARATOR . 'mapping.yml';
            // Parse the Google Sheet mapping YAML file into an array
            if (is_readable($mappingFile)) {
                $this->mapping = Yaml::parse(file_get_contents($mappingFile));
            } else {
                throw new \RuntimeException(sprintf('The mapping.yaml was not found at %s or is not readable.', $mappingFile));
            }
        }

        return $this->mapping;
    }

    /**
     * @param string $mappingId
     * @return array
     * @throws \Exception
     */
    public function loadSheets(string $mappingId): array {

        $mappingArray = $this->getMapping();
        // The URL is the only required mapping parameter
        if (isset($mappingArray[$mappingId]['url'])) {
            $mapping = &$mappingArray[$mappingId];
            $spreadsheetId = $this->getSpreadsheetId($mapping['url']);

            $service = new \Google_Service_Sheets($this->getClient());
            /** @var \Google_Service_Sheets_Spreadsheet $spreadsheet */
            $spreadsheet = $service->spreadsheets->get($spreadsheetId);

            // First thing we do is figuring out what sheets are there in the requested document
            $sheets = [];
            foreach($spreadsheet->getSheets() as $s) {
                /** @var \Google_Service_Sheets_SheetProperties $sp */
                $sp = &$s['properties'];

                // Skip all sheets with trailing underscore
                if (preg_match('~_$~', $sp->getTitle())) {
                    continue;
                }

                $sheets[$sp->getTitle()] = [
                    // TODO we could decide batchGet basedon the size of the batch. Do we care?
//                    'columnCount' => $sp->getGridProperties()->getColumnCount(),
//                    'rowCount' => $sp->getGridProperties()->getRowCount(),
                    'values' => []
                ];

                unset($s);
            }

            // Second step is to figure out what's the requested range. Did we want to skip some rows?
            // TODO: Each sheet might need different setting for this - yes? no?
            $startingRow = isset($mapping['startingFromRow']) ? (int)$mapping['startingFromRow'] : 1;
            $range = 'A'.$startingRow.':ZZ'; // A1:ZZ means the whole sheet

            // If we've got more than one sheet and batchGet is not blocked, let's download everything in one request
            if (count($sheets) > 1 && isset($mapping['batchGet']) && $mapping['batchGet']) {
                /** @var \Google_Service_Sheets_BatchGetValuesResponse $batchResult */
                $batchResult = $service->spreadsheets_values->batchGet(
                    $spreadsheetId,
                    ['ranges' => array_map(function($sheetName) use ($range) { return $sheetName.'!'.$range; }, array_keys($sheets))]
                );

                /** @var \Google_Service_Sheets_ValueRange $valueRange */
                foreach ($batchResult->getValueRanges() as $valueRange) {
                    preg_match('~^\'?([^\'!]+)\'?!~', $valueRange->getRange(), $matches);

                    if (!empty($matches) && isset($matches[1])) {
                        $sheets[$matches[1]]['values'] = $valueRange->getValues();
                    }
                    unset($valueRange, $matches);
                }
            } else {
                foreach ($sheets as $sheetName => &$sheetProperties) {
                    /** @var \Google_Service_Sheets_ValueRange $valueRange */
                    $valueRange = $service->spreadsheets_values->get($spreadsheetId, $sheetName.'!'.$range);
                    $sheetProperties['values'] = $valueRange->getValues();
                    unset($valueRange);
                }
            }

            return [$spreadsheet->getProperties()->getTitle(), $sheets];
        } else {
            throw new InvalidConfigurationException(sprintf('Mapping %s doesn\'t exist or has no URL configured.', $mappingId));
        }
    }

    /**
     * Extracts the document ID from the Sheet URL
     *
     * @param string $url
     * @return string
     */
    private function getSpreadsheetId(string $url): string {
        return preg_replace('~.*spreadsheets/d/([a-zA-Z0-9\-]+).*~', '$1', $url);
    }
}
