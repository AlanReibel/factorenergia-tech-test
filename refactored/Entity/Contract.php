<?php

namespace App\Entity;

class Contract
{
    private int $id;
    private int $tariffId;
    private string $country;
    private Tariff $tariff;

    public function __construct(
        int $id,
        int $tariffId,
        string $country,
        Tariff $tariff
    ) {
        $this->id = $id;
        $this->tariffId = $tariffId;
        $this->country = $country;
        $this->tariff = $tariff;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTariffId(): int
    {
        return $this->tariffId;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getTariff(): Tariff
    {
        return $this->tariff;
    }
}
