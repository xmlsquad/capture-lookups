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

    /** @var string */
    private $credentialsFileName;

    /** @var string Path to the used credentials file */
    private $credentialsFilePath;

    /** @var string */
    private $mappingFileName;

    /** @var string Path to the used mapping file */
    private $mappingFilePath;

    /**
     * GoogleApiService constructor.
     *
     * @param string $projectDir
     * @param string $mappingFileName
     * @param string $credentialsFileName
     */
    public function __construct(string $projectDir, string $mappingFileName, string $credentialsFileName)
    {
        $this->projectDir = $projectDir;
        $this->mappingFileName = $mappingFileName;
        $this->credentialsFileName = $credentialsFileName;
    }

    /**
     * A helper method allowing to use different credentials during the lifecycle of the application.
     *
     * @param null|string $credentialsFilePath
     *
     * @return string
     *
     * @throws \Exception
     */
    public function setCredentials(?string $credentialsFilePath = null): string
    {
        $this->client = null;
        $this->getClient($credentialsFilePath);

        return $this->getCredentialsFilePath();
    }

    /**
     * Returns path of the currently used credentials file.
     *
     * @return null|string
     */
    public function getCredentialsFilePath(): ?string
    {
        return $this->credentialsFilePath;
    }

    /**
     * Returns path of the currently used credentials file.
     *
     * @return null|string
     */
    public function getMappingFilePath(): ?string
    {
        return $this->mappingFilePath;
    }

    /**
     * Returns the array resulting from mapping.yml.
     *
     * @return array|mixed
     *
     * @throws \Exception
     */
    public function getMapping()
    {
        if (null === $this->mapping) {
            $mappingFilePath = $this->locateFile($this->mappingFileName);

            if (is_array($mappingFilePath)) {
                throw new \Exception("The mapping file wasn't found. Locations we tried: ".join(', ', $mappingFilePath));
            } elseif (!is_readable($mappingFilePath)) {
                throw new \RuntimeException(sprintf('The mapping.yaml was not found at %s or is not readable.', $mappingFilePath));
            } else {
                $this->mappingFilePath = $mappingFilePath;
                $this->mapping = Yaml::parse(file_get_contents($mappingFilePath));
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

                $sheets[preg_replace("~'~", '', $sp->getTitle())] = [
                    'title' => $sp->getTitle(),
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
                    ['ranges' => array_map(function ($sheet) use ($range) { return $sheet['title'].'!'.$range; }, array_values($sheets))]
                );

                /** @var \Google_Service_Sheets_ValueRange $valueRange */
                foreach ($batchResult->getValueRanges() as $valueRange) {

                    preg_match('~^(.*)![A-Z0-9]+:[A-Z0-9]+$~', $valueRange->getRange(), $matches);

                    if (!empty($matches) && isset($matches[1])) {
                        $sheetName = preg_replace("~'~", '', $matches[1]);

                        if (isset($sheets[$sheetName])) {
                            $sheets[$sheetName]['values'] = $valueRange->getValues();
                        } else {
                            throw new \Exception(sprintf('GSuite returned a sheet with name %s, which wasn\'t found in our sheet table. Please remove any special characters from the sheet name.', $valueRange->getRange()));
                        }
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
     * @param null|string $customCredentialsFilePath
     *
     * @return \Google_Client
     *
     * @throws \Google_Exception
     */
    protected function getClient(?string $customCredentialsFilePath = null): \Google_Client
    {
        if (null === $this->client) {
            $path = $this->locateFile($this->credentialsFileName, $customCredentialsFilePath);

            if (is_array($path)) {
                throw new \Exception("The credentials file wasn't found. Locations we tried: ".join(', ', $path));
            }

            $client = new \Google_Client();
            $client->setAuthConfig($path);
            $client->setApplicationName('capture-lookups');
            $client->setScopes([
                \Google_Service_Sheets::SPREADSHEETS_READONLY,
            ]);
            $client->setAccessType('offline');

            $this->client = $client;
            $this->credentialsFilePath = $path;
        }

        return $this->client;
    }

    /**
     * Looks for a specified file name in various locations.
     *
     * Returns path to the file if found, or array of tried locations if not found.
     *
     * Locations checked:
     *
     * - Project root dir
     * - Current working directory
     *
     * @param $fileName
     * @param null|string $userSuppliedPath
     *
     * @return string|array
     */
    private function locateFile($fileName, ?string $userSuppliedPath = null)
    {
        // These are the primary credentials file locations
        $locations = [
            // First check the project file
            $this->projectDir.DIRECTORY_SEPARATOR.$fileName,
        ];

        // Then the current working directory, providing it is different from the project directory
        if (getcwd() !== $this->projectDir) {
            $locations[] = getcwd().DIRECTORY_SEPARATOR.$fileName;
        }

        $locationsFailed = [];

        // Prepend the requested location if specified
        if (null !== $userSuppliedPath) {
            array_unshift($locations, $userSuppliedPath);
        }

        // In the first loop, we go through the primary locations.
        for ($loop = 1; $loop <= 2; ++$loop) {
            while ($path = array_shift($locations)) {
                if (file_exists($path) && is_readable($path)) {
                    // When a file is found, we break free from both loops and just carry on with our life
                    return $path;
                }

                $locationsFailed[] = $path;
            }

            // If we made it here during the first loop, primary locations didn't work. We'll work out all the directories above us and try them
            if (1 === $loop) {
                // This means we didn't fin'd the file when checking the default locations, so let's try everything we can

                // Add the root directory first
                $locations[] = DIRECTORY_SEPARATOR.$fileName;

                // We'll take CWD and project directory and will try to crawl all the way up to the root to attempt to load the file
                foreach ([$this->projectDir, getcwd()] as $leafDirectory) {
                    $path = '/';
                    foreach (array_filter(explode(DIRECTORY_SEPARATOR, dirname($leafDirectory))) as $directory) {
                        $path .= $directory.DIRECTORY_SEPARATOR;

                        // Don't add one location twice
                        if (false === array_search($path.$fileName, $locationsFailed)
                            && false === array_search($path.$fileName, $locations)
                            && is_dir($path)
                            && is_readable($path)
                        ) {
                            array_unshift($locations, $path.$fileName);
                        }
                    }
                }
            }

            // If we made it here during the second loop, neither primary nor secondary locations worked. We can't continue.
        }

        return $locationsFailed;
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
        return preg_replace('~.*spreadsheets/d/([^/]+).*~', '$1', $url);
    }
}
