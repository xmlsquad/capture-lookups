<?php

namespace AppBundle\Command;

use AppBundle\Service\GoogleApiService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class CaptureLookupsCommand extends ContainerAwareCommand
{
    /** @var GoogleApiService */
    private $googleApiService;

    public function __construct(GoogleApiService $googleApiService) {
        $this->googleApiService = $googleApiService;
        parent::__construct();
    }

    /** @inheritdoc */
    protected function configure()
    {
        $this
            ->setName('forikal:capture-lookups')
            ->setDescription('Downloads specified Google Sheet and saves it as a CSV.')
            ->addOption('destination', 'd', InputOption::VALUE_OPTIONAL, 'A path to a directory you want to store the resulting CSV files.')
            ->addOption('sheet', 's', InputOption::VALUE_OPTIONAL, 'Name of the sheet to download.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrites existing CSV files.')
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
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



        if (isset($mapping[$mappingName])) {

            $output->writeln(sprintf("<comment>Generated CSV files will be saved into %s.</comment>", $destination));
            $output->writeln('');

            $output->write('Fetching data from the Google API...');
            list($documentName, $sheets) = $this->googleApiService->loadSheets($mappingName);

            $output->writeln('done.');
            $output->writeln('');
            $output->writeln(sprintf('Document <info>%s</info> contains <info>%s</info> sheet(s). Writing CSV files now.', $documentName, count($sheets)));
            $output->writeln('');

            $helper = $this->getHelper('question');

            foreach ($sheets as $sheetName => $sheetData) {

                $file = $documentName.'-'.$sheetName.'.csv';

                $output->write(sprintf('Processing file <info>%s</info>. ', $file));

                // Check for potentially unsafe characters
                $regex = '~[^0-9a-z_\-\.\ ]~i';
                if (preg_match($regex, $file)) {

                    $safeFileName = iconv("UTF-8", "ISO-8859-1//TRANSLIT", $documentName.'-'.$sheetName);
                    $safeFileName = preg_replace($regex, '', $safeFileName).'.csv';

                    $question = new ConfirmationQuestion(
                        sprintf("<error>A risky file name detected.</error>\nI suggest to continue with a safe file name: <comment>%s</comment>.", $file, $safeFileName),
                        $forcedMode,
                        '/^(y|yes)/i'
                    );
                    if (!$helper->ask($input, $output, $question)) {
                        $output->writeln('<comment>Skipping (unsafe file name).</comment>');
                        continue;
                    } else {
                        $file = $safeFileName;
                        $output->writeln('Ok, continuing with the safe file name.');
                    }
                }

                $path = $destination. DIRECTORY_SEPARATOR . $file;

                if (file_exists($path)) {
//                    if (!$forcedMode) {
                        $question = new ConfirmationQuestion("<question>File already exists.</question>\nDo you wish to overwrite the file?", $forcedMode, '/^(y|yes)/i');

                        if (!$helper->ask($input, $output, $question)) {
                            $output->writeln('<comment>Skipping (file exists).</comment>');
                            continue;
                        }
//                    }
                }

                $fp = fopen($path, 'w');
                foreach ($sheetData['values'] as $row) {
                    fputcsv($fp, $row);
                }
                fclose($fp);

                $output->writeln('<comment>Done.</comment>');
            }

            $output->writeln('');
            $output->writeln('<comment>All CSV files saved successfully.</comment>');

        } else {
            $this->listKnownSheets($output);
        }
    }

    /**
     * @param OutputInterface $output
     */
    protected function listKnownSheets(OutputInterface $output) {
        $output->writeln('Please enter one of the configured Sheet names');

        $table = new Table($output);
        $table->setHeaders(['Sheet Name', 'URL']);

        foreach ($this->googleApiService->getMapping() as $mappingName => $mappingProperties) {
            $table->addRow([$mappingName, $mappingProperties['url']]);
        }

        $table->render();
    }

}
