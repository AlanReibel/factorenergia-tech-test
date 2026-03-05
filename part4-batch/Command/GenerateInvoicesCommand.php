<?php

namespace App\Command;

use App\Service\BatchInvoiceGenerator;
use App\Service\SummaryEmailer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use Exception;

class GenerateInvoicesCommand extends Command
{
    protected static $defaultName = 'invoices:generate-monthly';
    protected static $defaultDescription = 'Generate invoices for all active contracts for the previous month';

    private BatchInvoiceGenerator $batchGenerator;
    private SummaryEmailer $emailer;
    private LoggerInterface $logger;

    public function __construct(
        BatchInvoiceGenerator $batchGenerator,
        SummaryEmailer $emailer,
        LoggerInterface $logger
    ) {
        parent::__construct();
        $this->batchGenerator = $batchGenerator;
        $this->emailer = $emailer;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->setHelp('This command generates invoices for all active contracts for the previous month.')
            ->setDescription(self::$defaultDescription);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            $io->title('Monthly Invoice Generation Process');
            $io->text('Starting batch invoice generation for previous month...');

            // Get previous month in YYYY-MM format
            $previousMonth = $this->getPreviousMonth();
            $io->text("Processing billing period: <info>$previousMonth</info>");

            // Start timer
            $startTime = microtime(true);

            // Generate invoices in batch
            $stats = $this->batchGenerator->generateInvoicesForMonth($previousMonth, $io);

            // Calculate duration
            $duration = microtime(true) - $startTime;

            // Display results
            $this->displayResults($io, $stats, $duration);

            // Send summary email
            $this->logger->info('Sending summary email with batch results');
            $this->emailer->sendSummaryEmail($stats, $previousMonth);
            $io->success('Summary email sent to administrators');

            $this->logger->info('Batch invoice generation completed successfully', [
                'billing_period' => $previousMonth,
                'duration_seconds' => $duration,
                'stats' => $stats
            ]);

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->logger->critical('Batch invoice generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $io->error('Critical error during batch processing: ' . $e->getMessage());
            
            // Still try to send error notification
            try {
                $this->emailer->sendErrorNotification($e);
            } catch (Exception $emailError) {
                $this->logger->error('Failed to send error notification email', [
                    'error' => $emailError->getMessage()
                ]);
            }

            return Command::FAILURE;
        }
    }

    /**
     * Get the previous month in YYYY-MM format
     */
    private function getPreviousMonth(): string
    {
        $date = new \DateTimeImmutable('first day of last month');
        return $date->format('Y-m');
    }

    /**
     * Display batch results in console
     */
    private function displayResults(SymfonyStyle $io, array $stats, float $duration): void
    {
        $io->newLine();
        $io->section('Batch Processing Results');

        $table = $io->createTable();
        $table->setHeaders(['Metric', 'Value']);
        $table->setRows([
            ['Total Contracts Processed', (string) $stats['total']],
            ['<fg=green>Successfully Generated</>', (string) $stats['success']],
            ['<fg=yellow>Skipped (Duplicate)</>', (string) $stats['skipped']],
            ['<fg=red>Failed</>', (string) $stats['failed']],
            ['Success Rate', sprintf('%.2f%%', ($stats['success'] / $stats['total']) * 100)],
            ['Processing Time', sprintf('%.2f seconds', $duration)],
            ['Average per Contract', sprintf('%.3f seconds', $duration / $stats['total'])],
        ]);
        $table->render();

        if ($stats['failed'] > 0) {
            $io->newLine();
            $io->warning(sprintf('%d contracts failed processing', $stats['failed']));
            $io->text('Check logs for detailed failure information.');
        }
    }
}
