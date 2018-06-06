<?php

namespace Forikal\CaptureLookups\Tests\Command;

use Forikal\CaptureLookups\Command\CaptureLookupsCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\TestCase;

class CaptureLookupsCommandTest extends TestCase
{
    /**
     * @var Application
     */
    private $application;

    /**
     * Initialize application before each test
     */
    public function setUp()
    {
        $this->application = new Application();
        $this->application->add(new CaptureLookupsCommand());

        // Cleans up all Test*.csv files
        // in the /tmp directory from previous runs
        foreach($this->getLocalCsvFiles() as $csvFile) {
            unlink($csvFile);
        }
        
        parent::setUp();
    }

    public function testListMappings()
    {
        $command = $this->application->find('capture-lookups');
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
        $command = $this->application->find('capture-lookups');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command' => $command->getName(),
            '--sheet' => 'TestingSheet',
            '--destination' => '/tmp',
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
            '--destination' => '/tmp',
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
        $command = $this->application->find('capture-lookups');

        $commandSpec = [
            'command' => $command->getName(),
            '--sheet' => 'TestingSheetBatchGet',
            '--destination' => '/tmp',
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
        return glob('/tmp/Test*.csv');
    }
}