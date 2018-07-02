<?php

namespace XmlSquad\CaptureLookups\Command;

use XmlSquad\CaptureLookups\Service\GoogleApiService;
use XmlSquad\Library\Command\AbstractCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Class CaptureLookupsCommand.
 */
class CaptureLookupsCommand extends AbstractCommand
{
    const NAME = 'capture-lookups';

    /**
     * @var GoogleApiService
     */
    private $googleApiService;

    /**
     * CaptureLookupsCommand constructor.
     *
     * @param GoogleApiService|null $googleApiService
     */
    public function __construct(GoogleApiService $googleApiService = null)
    {
        if ($googleApiService instanceof GoogleApiService) {
            $this->googleApiService = $googleApiService;
        } else {
            $this->googleApiService = new GoogleApiService();
        }

        parent::__construct();
    }

    /** {@inheritdoc} */
    protected function configure()
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Downloads specified Google Sheet and saves it as a CSV.')
            ->addOption('destination', 'd', InputOption::VALUE_OPTIONAL, 'Path to a directory you want to store the resulting CSV files.')
            ->addOption('sheet', 's', InputOption::VALUE_OPTIONAL, 'Name of the sheet to download.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrites existing CSV files.')
            ->configureGApiServiceAccountCredentialsFileOption();

        ;
    }

    
    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int|null|void
     *
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('');

        $forcedMode = $mappingName = $input->getOption('force');
        $mappingName = $input->getOption('sheet');
        $mapping = $this->googleApiService->getMapping();
        $destination = realpath($input->getOption('destination') ?: getcwd());

        if (empty($destination)) {
            $output->writeln(sprintf('<error>The destination directory doesn\'t exist: %s</error>', $input->getOption('destination') ?: getcwd()));

            return;
        }

        if (!is_dir($destination)) {
            $output->writeln(sprintf('<error>The destination is not a valid directory: %s</error>', $destination));

            return;
        }

        if (!is_writable($destination)) {
            $output->writeln(sprintf('<error>The destination is not writable: %s</error>', $destination));

            return;
        }

        // If the mapping didn't exist, list all those which do
        if (!isset($mapping[$mappingName])) {
            $this->listKnownSheets($output);
        } else {
            $output->writeln(sprintf('<comment>Generated CSV files will be saved into %s.</comment>', $destination));
            $output->writeln('');

            // This is where we force the GoogleApiService to load a ceedentials file
            $output->writeln(sprintf(
                '<comment>Using gApiServiceAccountCredentials stored in %s.</comment>',
                $this->googleApiService->setCredentials($this->getGApiServiceAccountCredentialsFileOption($input))
            ));

            $output->writeln(sprintf(
                '<comment>Using sheet mapping file stored in %s.</comment>',
                $this->googleApiService->getMappingFilePath()
            ));
            $output->writeln('');

            $output->write('Fetching data from the Google API...');

            // The service does the API-related job and returns an array of sheets and their values together with the document name
            list($documentName, $sheets) = $this->googleApiService->loadSheets($mappingName);

            $output->writeln('done.');
            $output->writeln('');
            $output->writeln(sprintf('Document <info>%s</info> contains <info>%s</info> sheet(s). Writing CSV files now.', $documentName, count($sheets)));
            $output->writeln('');

            // Default ConfirmationQuestion action depends on the $forcedMode settings. Forced == off -> $default = false. Forced == on -> $default = true
            $default = $forcedMode ? '[Y/n]' : '[y/N]';

            // We'll be using this helper in the loop in a while, so let's fetch it just once
            $helper = $this->getHelper('question');

            foreach ($sheets as $sheetName => $sheetData) {
                // This file name is potentially dangerous. More ont hat later.
                $file = $documentName.'-'.$sheetName.'.csv';

                $output->write(sprintf('Processing file <info>%s</info>. ', $file));

                // Checks for potentially unsafe characters in the file name. If it is a risky file name, a remedy is offered.
                $regex = '~[^0-9a-z_\-\.\ ]~i';
                if (preg_match($regex, $file)) {
                    $safeFileName = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $documentName.'-'.$sheetName);
                    $safeFileName = trim(preg_replace($regex, '', $safeFileName)).'.csv';

                    // Removes multiple spaces too
                    $safeFileName = preg_replace('~\ {2,}~', ' ', $safeFileName);

                    $question = new ConfirmationQuestion(
                        sprintf("<error>A risky file name detected.</error>\nI suggest to continue with a safe file name: <comment>%s</comment>. Do you agree? %s ", $safeFileName, $default),
                        $forcedMode,
                        '/^(y|yes)/i'
                    );
                    if (!$helper->ask($input, $output, $question)) {
                        $output->writeln('<comment>Skipping (unsafe file name).</comment>');
                        continue;
                    } else {
                        $file = $safeFileName;
                        $output->write('Ok, continuing with the safe file name. ');
                    }
                }

                // At this stage we know that $file continues a safe file name - if the file name is risky and is not auto-corrected, the file is skipped.
                $path = $destination.DIRECTORY_SEPARATOR.$file;

                // Last check before overwriting the file
                if (file_exists($path)) {
                    $question = new ConfirmationQuestion(sprintf("<question>File already exists.</question>\nDo you wish to overwrite the file? %s ", $default), $forcedMode, '/^(y|yes)/i');

                    if (!$helper->ask($input, $output, $question)) {
                        $output->writeln('<comment>Skipping (file exists).</comment>');
                        continue;
                    }
                }

                // If we came here, everything either passed, or was accepted - so let's make our CSV file.
                $fp = fopen($path, 'w');
                foreach ($sheetData['values'] as $row) {
                    fputcsv($fp, $row);
                }
                fclose($fp);

                $output->writeln('<comment>Done.</comment>');
            }

            $output->writeln('');
            $output->writeln('<comment>All CSV files saved successfully.</comment>');
        }
    }

    /**
     * @param OutputInterface $output
     *
     * @throws \Exception
     */
    protected function listKnownSheets(OutputInterface $output)
    {
        $output->writeln('Please enter one of the configured Sheet names');

        $table = new Table($output);
        $table->setHeaders(['Sheet Name', 'URL']);

        foreach ($this->googleApiService->getMapping() as $mappingName => $mappingProperties) {
            if (isset($mappingProperties['url'])) {
                $table->addRow([$mappingName, $mappingProperties['url']]);
            }
        }

        $table->render();
    }
}
