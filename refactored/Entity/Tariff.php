<?php

namespace App\Entity;

class Tariff
{
    private int $id;
    private string $code;
    private float $pricePerKwh;
    private float $fixedMonthly;

    public function __construct(
        int $id,
        string $code,
        float $pricePerKwh,
        float $fixedMonthly
    ) {
        $this->id = $id;
        $this->code = $code;
        $this->pricePerKwh = $pricePerKwh;
        $this->fixedMonthly = $fixedMonthly;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getPricePerKwh(): float
    {
        return $this->pricePerKwh;
    }

    public function getFixedMonthly(): float
    {
        return $this->fixedMonthly;
    }
}
