<?php

namespace AppBundle\Service;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Yaml\Yaml;

class GoogleApiService
{
    /** @var \Google_Client */
    private $client;

    /** @var array Parsed mapping.yml */
    private $mapping;

    /** @var string Symfony project directory */
    private $projectDir;

    /** @var string Path to the used credentials file */
    private $credentialsFile;

    /**
     * GoogleApiService constructor.
     *
     * @param string $credentialsFile
     * @param string $mappingFile
     *
     * @throws \Google_Exception
     */
    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

    /**
     * A helper method allowing to use different credentials during the lifecycle of the application.
     *
     * @param null|string $credentialsFile
     *
     * @return string
     *
     * @throws \Exception
     */
    public function setCredentials(?string $credentialsFile = null): string
    {
        $this->client = null;
        $this->getClient($credentialsFile);

        return $this->getCredentialsFile();
    }

    /**
     * Returns path of the currently used credentials file.
     *
     * @return null|string
     */
    public function getCredentialsFile(): ?string
    {
        return $this->credentialsFile;
    }

    /**
     * Returns the array resulting from mapping.yml.
     *
     * @return array
     */
    public function getMapping()
    {
        if (null === $this->mapping) {
            $mappingFile = $this->projectDir.DIRECTORY_SEPARATOR.'mapping.yml';
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
     *
     * @return array
     *
     * @throws \Exception
     */
    public function loadSheets(string $mappingId): array
    {
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
            foreach ($spreadsheet->getSheets() as $s) {
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
                    'values' => [],
                ];

                unset($s);
            }

            // Second step is to figure out what's the requested range. Did we want to skip some rows?
            // TODO: Each sheet might need different setting for this - yes? no?
            $startingRow = isset($mapping['startingFromRow']) ? (int) $mapping['startingFromRow'] : 1;
            $range = 'A'.$startingRow.':ZZ'; // A1:ZZ means the whole sheet

            // If we've got more than one sheet and batchGet is not blocked, let's download everything in one request
            if (count($sheets) > 1 && isset($mapping['batchGet']) && $mapping['batchGet']) {
                /** @var \Google_Service_Sheets_BatchGetValuesResponse $batchResult */
                $batchResult = $service->spreadsheets_values->batchGet(
                    $spreadsheetId,
                    ['ranges' => array_map(function ($sheetName) use ($range) { return $sheetName.'!'.$range; }, array_keys($sheets))]
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
     * @param null|string $credentialsFile
     *
     * @return \Google_Client
     *
     * @throws \Google_Exception
     */
    protected function getClient(?string $credentialsFile = null): \Google_Client
    {
        if (null === $this->client) {
            $locations = [
                // First check the project file
                $this->projectDir.DIRECTORY_SEPARATOR.'credentials.json',
                // Then the current working directory
                getcwd().DIRECTORY_SEPARATOR.'credentials.json',
            ];

            $locationsFailed = [];

            // Prepend the requested location if specified
            if (null !== $credentialsFile) {
                array_unshift($locations, $credentialsFile);
            }

            for ($loop = 1; $loop <= 2; ++$loop) {
                while ($path = array_shift($locations)) {
                    if (file_exists($path) && is_readable($path)) {
                        break 2;
                    }

                    $locationsFailed[] = $path;
                }

                if (1 === $loop) {
                    // This means we didn't fin'd the file when checking the default locations.
                    $path = '/';
                    foreach (array_filter(explode(DIRECTORY_SEPARATOR, dirname(getcwd()))) as $directory) {
                        $path .= $directory.DIRECTORY_SEPARATOR;
                        array_unshift($locations, $path.'credentials.json');
                    }
                }
            }

            if (!$path) {
                throw new \Exception("The credentials file wasn't found. Locations we tried: ".join(', ', $locationsFailed));
            } else {
                $this->credentialsFile = $path;
            }

            // Set up the API client
            $client = new \Google_Client();
            $client->setAuthConfig($path);
            $client->setApplicationName('capture-lookups');
            $client->setScopes([
                \Google_Service_Sheets::SPREADSHEETS_READONLY,
            ]);
            $client->setAccessType('offline');

            $this->client = $client;
        }

        return $this->client;
    }

    /**
     * Extracts the document ID from the Sheet URL.
     *
     * @param string $url
     *
     * @return string
     */
    private function getSpreadsheetId(string $url): string
    {
        return preg_replace('~.*spreadsheets/d/([a-zA-Z0-9\-]+).*~', '$1', $url);
    }
}
