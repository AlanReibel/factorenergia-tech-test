<?php

namespace App\Service;

use App\Repository\ContractRepository;
use App\Repository\InvoiceRepository;
use App\Exception\ContractNotFoundException;
use App\Exception\TariffCalculationException;
use App\Exception\ExternalApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Exception;

class BatchInvoiceGenerator
{
    private const BATCH_SIZE = 100;
    
    private ContractRepository $contractRepository;
    private InvoiceRepository $invoiceRepository;
    private InvoiceService $invoiceService;
    private LoggerInterface $logger;

    public function __construct(
        ContractRepository $contractRepository,
        InvoiceRepository $invoiceRepository,
        InvoiceService $invoiceService,
        LoggerInterface $logger
    ) {
        $this->contractRepository = $contractRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->invoiceService = $invoiceService;
        $this->logger = $logger;
    }

    /**
     * Generate invoices for all active contracts for a given month
     */
    public function generateInvoicesForMonth(
        string $billingPeriod,
        ?SymfonyStyle $io = null
    ): array {
        $this->logger->info('Starting batch invoice generation', [
            'billing_period' => $billingPeriod,
            'batch_size' => self::BATCH_SIZE
        ]);

        // Initialize statistics
        $stats = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'failed_contracts' => [],
        ];

        try {
            // Get all active contract IDs (paginated to reduce memory)
            $contractIds = $this->contractRepository->findAllActiveContractIds();
            $stats['total'] = count($contractIds);

            if ($io) {
                $io->text("Found <info>{$stats['total']}</info> active contracts to process");
            }

            // Process contracts in batches
            $batches = array_chunk($contractIds, self::BATCH_SIZE);
            $processedCount = 0;

            foreach ($batches as $batchNumber => $contractBatch) {
                foreach ($contractBatch as $contractId) {
                    $this->processContractInvoice(
                        $contractId,
                        $billingPeriod,
                        $stats
                    );

                    $processedCount++;

                    // Display progress every 500 contracts
                    if ($io && $processedCount % 500 === 0) {
                        $io->text(sprintf(
                            'Processing... %d/%d contracts (%s)',
                            $processedCount,
                            $stats['total'],
                            date('Y-m-d H:i:s')
                        ));
                    }
                }
            }

            $this->logger->info('Successfully completed batch processing', $stats);

        } catch (Exception $e) {
            $this->logger->error('Unexpected error during batch processing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'stats_so_far' => $stats
            ]);
            throw $e;
        }

        return $stats;
    }

    /**
     * Process a single contract invoice with error handling
     */
    private function processContractInvoice(
        int $contractId,
        string $billingPeriod,
        array &$stats
    ): void {
        try {
            // Check if invoice already exists (prevent duplicates)
            if ($this->invoiceRepository->existsForPeriod($contractId, $billingPeriod)) {
                $this->logger->debug('Invoice already exists for contract', [
                    'contract_id' => $contractId,
                    'billing_period' => $billingPeriod
                ]);
                $stats['skipped']++;
                return;
            }

            // Generate the invoice
            $invoice = $this->invoiceService->createInvoice($contractId, $billingPeriod);

            // Log success
            $this->logger->info('Invoice generated successfully', [
                'contract_id' => $contractId,
                'invoice_id' => $invoice->getId(),
                'billing_period' => $billingPeriod,
                'amount' => $invoice->getTotalAmount(),
                'kwh' => $invoice->getTotalKwh()
            ]);

            $stats['success']++;

        } catch (ContractNotFoundException $e) {
            // Contract not found - likely deleted or inactive
            $this->logger->warning('Contract not found during invoice generation', [
                'contract_id' => $contractId,
                'error' => $e->getMessage()
            ]);
            $stats['failed']++;
            $stats['failed_contracts'][] = [
                'contract_id' => $contractId,
                'reason' => 'Contract not found'
            ];

        } catch (TariffCalculationException $e) {
            // Tariff calculation failed
            $this->logger->error('Tariff calculation failed for contract', [
                'contract_id' => $contractId,
                'error' => $e->getMessage()
            ]);
            $stats['failed']++;
            $stats['failed_contracts'][] = [
                'contract_id' => $contractId,
                'reason' => 'Tariff calculation failed: ' . $e->getMessage()
            ];

        } catch (ExternalApiException $e) {
            // External API failure (e.g., market data)
            $this->logger->error('External API error during invoice generation', [
                'contract_id' => $contractId,
                'error' => $e->getMessage()
            ]);
            $stats['failed']++;
            $stats['failed_contracts'][] = [
                'contract_id' => $contractId,
                'reason' => 'External API error: ' . $e->getMessage()
            ];

        } catch (Exception $e) {
            // Unexpected error - log and continue
            $this->logger->error('Unexpected error during invoice generation', [
                'contract_id' => $contractId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $stats['failed']++;
            $stats['failed_contracts'][] = [
                'contract_id' => $contractId,
                'reason' => 'Unexpected error: ' . $e->getMessage()
            ];
        }
    }
}
