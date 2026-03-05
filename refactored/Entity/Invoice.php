<?php

namespace App\Entity;

class Invoice
{
    private ?int $id = null;
    private int $contractId;
    private string $billingPeriod;
    private float $totalKwh;
    private float $totalAmount;
    private string $status;

    public function __construct(
        int $contractId,
        string $billingPeriod,
        float $totalKwh,
        float $totalAmount,
        string $status = 'draft'
    ) {
        $this->contractId = $contractId;
        $this->billingPeriod = $billingPeriod;
        $this->totalKwh = $totalKwh;
        $this->totalAmount = $totalAmount;
        $this->status = $status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getContractId(): int
    {
        return $this->contractId;
    }

    public function getBillingPeriod(): string
    {
        return $this->billingPeriod;
    }

    public function getTotalKwh(): float
    {
        return $this->totalKwh;
    }

    public function getTotalAmount(): float
    {
        return $this->totalAmount;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}
