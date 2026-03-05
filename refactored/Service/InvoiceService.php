<?php

namespace App\Service;

use App\Entity\Invoice;
use App\Repository\ContractRepository;
use App\Repository\InvoiceRepository;
use App\Repository\MeterReadingRepository;
use App\Service\TariffCalculator\TariffCalculatorFactory;
use App\Exception\ContractNotFoundException;
use App\Exception\TariffCalculationException;
use App\Exception\ExternalApiException;
use Psr\Log\LoggerInterface;

class InvoiceService
{
    private ContractRepository $contractRepository;
    private MeterReadingRepository $meterRepository;
    private InvoiceRepository $invoiceRepository;
    private TariffCalculatorFactory $tariffFactory;
    private TaxCalculator $taxCalculator;
    private LoggerInterface $logger;

    public function __construct(
        ContractRepository $contractRepository,
        MeterReadingRepository $meterRepository,
        InvoiceRepository $invoiceRepository,
        TariffCalculatorFactory $tariffFactory,
        TaxCalculator $taxCalculator,
        LoggerInterface $logger
    ) {
        $this->contractRepository = $contractRepository;
        $this->meterRepository = $meterRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->tariffFactory = $tariffFactory;
        $this->taxCalculator = $taxCalculator;
        $this->logger = $logger;
    }

    /**
     * Calculate and create an invoice for a contract
     * 
     * @param int $contractId ID of the contract
     * @param string $month Billing period (YYYY-MM)
     * @return Invoice The created invoice
     * 
     * @throws ContractNotFoundException If contract doesn't exist
     * @throws TariffCalculationException If tariff calculation fails
     * @throws ExternalApiException If external API fails
     */
    public function createInvoice(int $contractId, string $month): Invoice
    {
        // Load contract with tariff
        $contract = $this->contractRepository->findById($contractId);
        if (!$contract) {
            $this->logger->warning(
                "Contract not found",
                ['contract_id' => $contractId]
            );
            throw new ContractNotFoundException(
                "Contract with ID $contractId not found"
            );
        }

        // Get meter readings for the period
        $totalKwh = $this->meterRepository->getTotalKwhForPeriod($contractId, $month);

        // Calculate amount using strategy pattern (polymorphic)
        $tariff = $contract->getTariff();
        $calculator = $this->tariffFactory->createCalculator(
            $tariff->getCode(),
            $month,
            $tariff->getPricePerKwh()
        );

        $amount = $calculator->calculate(
            $totalKwh,
            $tariff->getFixedMonthly()
        );

        // Calculate tax based on country
        $tax = $this->taxCalculator->calculateTax(
            $amount,
            $contract->getCountry()
        );

        $totalAmount = $amount + $tax;

        // Create and persist invoice
        $invoice = new Invoice(
            $contractId,
            $month,
            $totalKwh,
            $totalAmount,
            'draft'
        );

        $this->invoiceRepository->save($invoice);

        // Log successful creation
        $this->logger->info(
            "Invoice created successfully",
            [
                'contract_id' => $contractId,
                'month' => $month,
                'total_amount' => $totalAmount,
                'total_kwh' => $totalKwh,
                'tariff_code' => $tariff->getCode()
            ]
        );

        return $invoice;
    }
}
