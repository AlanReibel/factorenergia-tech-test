<?php

namespace App\Entity;

class Contract
{
    private int $id;
    private int $tariffId;
    private string $country;
    private Tariff $tariff;

    // additional fields used for ERSE sync
    private string $nif;
    private string $cups;
    private string $streetAddress;
    private string $city;
    private string $postalCode;
    private \DateTimeInterface $startDate;
    private float $estimatedAnnualKwh;

    public function __construct(
        int $id,
        int $tariffId,
        string $country,
        Tariff $tariff,
        string $nif = '',
        string $cups = '',
        string $streetAddress = '',
        string $city = '',
        string $postalCode = '',
        ?\DateTimeInterface $startDate = null,
        float $estimatedAnnualKwh = 0.0
    ) {
        $this->id = $id;
        $this->tariffId = $tariffId;
        $this->country = $country;
        $this->tariff = $tariff;
        $this->nif = $nif;
        $this->cups = $cups;
        $this->streetAddress = $streetAddress;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->startDate = $startDate ?? new \DateTimeImmutable();
        $this->estimatedAnnualKwh = $estimatedAnnualKwh;
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

    // additional getters for ERSE payload
    public function getNif(): string
    {
        return $this->nif;
    }

    public function getCups(): string
    {
        return $this->cups;
    }

    public function getStreetAddress(): string
    {
        return $this->streetAddress;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function getStartDate(): \DateTimeInterface
    {
        return $this->startDate;
    }

    public function getEstimatedAnnualKwh(): float
    {
        return $this->estimatedAnnualKwh;
    }
}
