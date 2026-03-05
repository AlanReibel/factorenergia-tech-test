<?php

namespace App\Part3Api\Repository;

use App\Part3Api\Entity\ContractSync;
use PDO;

class ContractSyncRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function save(ContractSync $sync): void
    {
        if (isset($sync->getId())) {
            // update
            $stmt = $this->pdo->prepare(
                "UPDATE contract_syncs
                 SET contract_id = :contract_id,
                     erse_external_id = :erse_external_id,
                     status = :status,
                     response_payload = :response_payload,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $stmt->execute([
                'contract_id' => $sync->getContractId(),
                'erse_external_id' => $sync->getErseExternalId(),
                'status' => $sync->getStatus(),
                'response_payload' => $sync->getResponsePayload(),
                'id' => $sync->getId(),
            ]);
        } else {
            // insert
            $stmt = $this->pdo->prepare(
                "INSERT INTO contract_syncs
                 (contract_id, erse_external_id, status, response_payload, created_at, updated_at)
                 VALUES (:contract_id, :erse_external_id, :status, :response_payload, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $stmt->execute([
                'contract_id' => $sync->getContractId(),
                'erse_external_id' => $sync->getErseExternalId(),
                'status' => $sync->getStatus(),
                'response_payload' => $sync->getResponsePayload(),
            ]);
            $syncId = (int)$this->pdo->lastInsertId();
            // set id via reflection or modify entity - we didn't implement setter
            $reflection = new \ReflectionClass($sync);
            $prop = $reflection->getProperty('id');
            $prop->setAccessible(true);
            $prop->setValue($sync, $syncId);
        }
    }

    public function findPendingByContractId(int $contractId): ?ContractSync
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM contract_syncs WHERE contract_id = :contract_id AND status = :status LIMIT 1"
        );
        $stmt->execute([
            'contract_id' => $contractId,
            'status' => ContractSync::STATUS_PENDING
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $sync = new ContractSync(
            (int) $row['contract_id'],
            $row['status'],
            $row['response_payload'],
            $row['erse_external_id']
        );
        // set id and timestamps
        $ref = new \ReflectionClass($sync);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($sync, (int)$row['id']);
        $createdProp = $ref->getProperty('createdAt');
        $createdProp->setAccessible(true);
        $createdProp->setValue($sync, new \DateTimeImmutable($row['created_at']));
        $updatedProp = $ref->getProperty('updatedAt');
        $updatedProp->setAccessible(true);
        $updatedProp->setValue($sync, new \DateTimeImmutable($row['updated_at']));
        return $sync;
    }
}
