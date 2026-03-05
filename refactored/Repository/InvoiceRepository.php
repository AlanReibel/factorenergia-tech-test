<?php

namespace App\Repository;

use App\Entity\Invoice;
use PDO;

class InvoiceRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function save(Invoice $invoice): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO invoices 
             (contract_id, billing_period, total_kwh, total_amount, status)
             VALUES (:contract_id, :billing_period, :total_kwh, :total_amount, :status)"
        );

        $stmt->execute([
            'contract_id' => $invoice->getContractId(),
            'billing_period' => $invoice->getBillingPeriod(),
            'total_kwh' => $invoice->getTotalKwh(),
            'total_amount' => $invoice->getTotalAmount(),
            'status' => $invoice->getStatus()
        ]);
    }

    /**
     * Check if an invoice already exists for a contract and billing period
     * Used to prevent duplicate invoice generation
     */
    public function existsForPeriod(int $contractId, string $billingPeriod): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1
             FROM invoices
             WHERE contract_id = :contract_id
             AND billing_period = :billing_period
             LIMIT 1"
        );

        $stmt->execute([
            'contract_id' => $contractId,
            'billing_period' => $billingPeriod
        ]);

        return $stmt->fetch() !== false;
    }
}
