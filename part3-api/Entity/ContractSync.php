<?php

namespace App\Part3Api\Entity;

class ContractSync
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    private ?int $id = null;
    private int $contractId;
    private ?string $erseExternalId;
    private string $status;
    private ?string $responsePayload;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;

    public function __construct(
        int $contractId,
        string $status = self::STATUS_PENDING,
        ?string $responsePayload = null,
        ?string $erseExternalId = null
    ) {
        $this->contractId = $contractId;
        $this->status = $status;
        $this->responsePayload = $responsePayload;
        $this->erseExternalId = $erseExternalId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    // getters and setters
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContractId(): int
    {
        return $this->contractId;
    }

    public function getErseExternalId(): ?string
    {
        return $this->erseExternalId;
    }

    public function setErseExternalId(?string $erseExternalId): void
    {
        $this->erseExternalId = $erseExternalId;
        $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function getResponsePayload(): ?string
    {
        return $this->responsePayload;
    }

    public function setResponsePayload(?string $payload): void
    {
        $this->responsePayload = $payload;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
