<?php

namespace App\Tests\Command;

use App\Command\GenerateInvoicesCommand;
use App\Service\BatchInvoiceGenerator;
use App\Service\SummaryEmailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Psr\Log\LoggerInterface;

class GenerateInvoicesCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private BatchInvoiceGenerator $batchGenerator;
    private SummaryEmailer $emailer;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->batchGenerator = $this->createMock(BatchInvoiceGenerator::class);
        $this->emailer = $this->createMock(SummaryEmailer::class);

        $command = new GenerateInvoicesCommand(
            $this->batchGenerator,
            $this->emailer,
            $this->logger
        );

        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($command);
    }

    public function testCommandExecutesSuccessfully()
    {
        // Mock successful batch generation
        $stats = [
            'total' => 100,
            'success' => 98,
            'skipped' => 1,
            'failed' => 1,
            'failed_contracts' => [
                ['contract_id' => 123, 'reason' => 'Not found']
            ]
        ];

        $this->batchGenerator
            ->expects($this->once())
            ->method('generateInvoicesForMonth')
            ->with('2026-02')
            ->willReturn($stats);

        $this->emailer
            ->expects($this->once())
            ->method('sendSummaryEmail')
            ->with($stats, '2026-02');

        // Execute command
        $exitCode = $this->commandTester->execute([]);

        // Assertions
        $this->assertEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Monthly Invoice Generation Process', $output);
        $this->assertStringContainsString('98', $output);
        $this->assertStringContainsString('Summary email sent', $output);
    }

    public function testCommandHandlesException()
    {
        $exception = new \Exception('Database connection failed');

        $this->batchGenerator
            ->expects($this->once())
            ->method('generateInvoicesForMonth')
            ->willThrowException($exception);

        $this->emailer
            ->expects($this->once())
            ->method('sendErrorNotification')
            ->with($exception);

        $exitCode = $this->commandTester->execute([]);

        $this->assertEquals(1, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Database connection failed', $output);
    }

    public function testResultsTableIsDisplayed()
    {
        $stats = [
            'total' => 10000,
            'success' => 9950,
            'skipped' => 30,
            'failed' => 20,
            'failed_contracts' => []
        ];

        $this->batchGenerator
            ->expects($this->once())
            ->method('generateInvoicesForMonth')
            ->willReturn($stats);

        $this->commandTester->execute([]);
        $output = $this->commandTester->getDisplay();

        // Check table values
        $this->assertStringContainsString('10000', $output); // Total
        $this->assertStringContainsString('9950', $output);  // Success
        $this->assertStringContainsString('99.50%', $output); // Success rate
    }
}
