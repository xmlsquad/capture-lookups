<?php

namespace AppBundle\Tests\Command;

use AppBundle\Command\CaptureLookupsCommand;
use AppBundle\Service\GoogleApiService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CaptureLookupsCommandTest extends KernelTestCase
{
    /** @var Application */
    private $application;

    private $destination = './var/tmp';

    public function setUp()
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $gas = new GoogleApiService(
            realpath($kernel->getRootDir().'/../'),
            $kernel->getContainer()->getParameter('mapping_file_name'),
            $kernel->getContainer()->getParameter('credentials_file_name')
        );

        $application->add(new CaptureLookupsCommand($gas));

        $this->application = $application;

        // Cleans up all Test*.csv files in the var/tmp directory from previous runs
        foreach($this->getLocalCsvFiles() as $csvFile) {
            unlink($csvFile);
        }
        
        parent::setUp();
    }

    public function tearDown()
    {
        parent::tearDown();
    }

    public function testListMappings()
    {
        $command = $this->application->find('forikal:capture-lookups');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command'  => $command->getName(),
        ));

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertContains('Please enter one of the configured Sheet names', $output);
        $this->assertContains('TestingSheet', $output);
        $this->assertContains('TestingSheetBatchGet', $output);
    }

    public function testNotForced()
    {
        $command = $this->application->find('forikal:capture-lookups');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command' => $command->getName(),
            '--sheet' => 'TestingSheet',
            '--destination' => './var/tmp',
        ), [
            'interactive' => false
        ]);

        $output = $commandTester->getDisplay();

        // Two sheets, one downloaded, second skipped
        $this->assertContains('2 sheet(s)', $output);
        $this->assertContains('Done.', $output);
        $this->assertContains('Skipping (unsafe file name).', $output);

        $files = $this->getLocalCsvFiles();
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('Testing Public Sheet-Test Sheet1.csv', $files[0]);

        // Let's run it again to confirm the existing file will be skipped
        $commandTester->execute(array(
            'command' => $command->getName(),
            '--sheet' => 'TestingSheet',
            '--destination' => './var/tmp',
        ), [
            'interactive' => false
        ]);

        $output = $commandTester->getDisplay();

        // Two sheets, both skipped
        $this->assertContains('2 sheet(s)', $output);
        $this->assertContains('Skipping (file exists).', $output);
        $this->assertContains('Skipping (unsafe file name).', $output);

        // Make sure the resulting CSV matches expectations
        $this->assertEquals(
            "KNumberExists,KNumber,AlternativeNumber,SafetyNotes,UKManufacturer,LineStatus\ntrue,11111111,,Flamable,Acme,Active\ntrue,2222222,,Explosive,FooBarBoo,Deprecated\n",
            file_get_contents($files[0])
        );
    }

    public function testBatchGetForced()
    {
        $command = $this->application->find('forikal:capture-lookups');

        $commandSpec = [
            'command' => $command->getName(),
            '--sheet' => 'TestingSheetBatchGet',
            '--destination' => './var/tmp',
            '--force' => true
        ];

        $commandTester = new CommandTester($command);
        $commandTester->execute($commandSpec, [
            'interactive' => false
        ]);

        $output = $commandTester->getDisplay();

        // We run this twice, because the second time the files already exist and we want to make sure they are overwritten
        for($i = 0; $i < 2; $i++) {
            // Two sheets, both downloaded
            $this->assertContains('2 sheet(s)', $output);
            $this->assertContains('Done.', $output);
            $this->assertContains('Ok, continuing with the safe file name.', $output);

            $files = $this->getLocalCsvFiles();
            $this->assertCount(2, $files);
            $this->assertStringEndsWith('Testing Public Sheet-Test Sheet1.csv', $files[0]);
            $this->assertStringEndsWith('Testing Public Sheet-Test Sheet2 Funky Name.csv', $files[1]);
        }
    }

    /**
     * @return array
     */
    private function getLocalCsvFiles() {
        return glob(realpath($this->application->getKernel()->getRootDir().'/../'.$this->destination).'/Test*.csv');
    }
}