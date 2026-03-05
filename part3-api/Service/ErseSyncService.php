<?php

namespace App\Part3Api\Service;

use App\Repository\ContractRepository;
use App\Part3Api\Repository\ContractSyncRepository;
use App\Part3Api\Entity\ContractSync;
use App\Exception\ContractNotFoundException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class ErseSyncService
{
    private ContractRepository $contractRepository;
    private ContractSyncRepository $syncRepository;
    private HttpClientInterface $httpClient;
    private string $erseUrl;
    private string $erseToken;
    private LoggerInterface $logger;

    public function __construct(
        ContractRepository $contractRepository,
        ContractSyncRepository $syncRepository,
        HttpClientInterface $httpClient,
        string $erseUrl,
        string $erseToken,
        LoggerInterface $logger
    ) {
        $this->contractRepository = $contractRepository;
        $this->syncRepository = $syncRepository;
        $this->httpClient = $httpClient;
        $this->erseUrl = rtrim($erseUrl, '/');
        $this->erseToken = $erseToken;
        $this->logger = $logger;
    }

    /**
     * Synchronize a single contract to ERSE service.
     *
     * @param int $contractId
     * @return ContractSync the record that tracks the attempt
     * @throws ContractNotFoundException
     */
    public function syncContract(int $contractId): ContractSync
    {
        // create initial pending record
        $sync = new ContractSync($contractId);
        $this->syncRepository->save($sync);

        // load contract
        $contract = $this->contractRepository->findById($contractId);
        if (!$contract) {
            $this->logger->warning('Contract not found during ERSE sync', ['contract_id' => $contractId]);
            $sync->setStatus(ContractSync::STATUS_FAILED);
            $sync->setResponsePayload('contract_not_found');
            $this->syncRepository->save($sync);
            throw new ContractNotFoundException("Contract $contractId not found");
        }

        if (strtoupper($contract->getCountry()) !== 'PT') {
            $this->logger->warning('Attempt to sync non-PT contract', ['contract_id' => $contractId]);
            $sync->setStatus(ContractSync::STATUS_FAILED);
            $sync->setResponsePayload('country_not_pt');
            $this->syncRepository->save($sync);
            return $sync;
        }

        // transform to ERSE payload; assume contract has getters for required fields
        $payload = [
            'nif' => $contract->getNif(),
            'cups' => $contract->getCups(),
            'supply_address' => [
                'street' => $contract->getStreetAddress(),
                'city' => $contract->getCity(),
                'postal_code' => $contract->getPostalCode(),
            ],
            'tariff_code' => $contract->getTariff()->getCode(),
            'start_date' => $contract->getStartDate()->format('Y-m-d'),
            'estimated_annual_kwh' => $contract->getEstimatedAnnualKwh(),
        ];

        try {
            $response = $this->httpClient->request('POST', $this->erseUrl . '/contracts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->erseToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => $payload
            ]);
        } catch (TransportExceptionInterface $e) {
            // network error
            $this->logger->error('HTTP transport error during ERSE sync', ['exception' => $e]);
            $sync->setStatus(ContractSync::STATUS_FAILED);
            $sync->setResponsePayload($e->getMessage());
            $this->syncRepository->save($sync);
            return $sync;
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getContent(false);
        $sync->setResponsePayload($body);

        if ($status === 201) {
            $data = json_decode($body, true);
            $sync->setStatus(ContractSync::STATUS_SUCCESS);
            $sync->setErseExternalId($data['erse_id'] ?? null);
        } elseif ($status === 400) {
            $sync->setStatus(ContractSync::STATUS_FAILED);
        } elseif ($status === 409) {
            $sync->setStatus(ContractSync::STATUS_FAILED);
        } else {
            // treat other codes (500 etc) as failure
            $sync->setStatus(ContractSync::STATUS_FAILED);
        }

        $this->syncRepository->save($sync);
        return $sync;
    }
}
