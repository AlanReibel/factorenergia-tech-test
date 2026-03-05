<?php

namespace App\Part3Api\Controller;

use App\Part3Api\Service\ErseSyncService;
use App\Exception\ContractNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ContractSyncController extends AbstractController
{
    private ErseSyncService $syncService;

    public function __construct(ErseSyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * POST /api/contracts/sync
     * body: { "contract_id": 123 }
     */
    public function sync(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['contract_id'])) {
            return new JsonResponse([
                'status' => 'error',
                'error' => 'invalid_request',
                'message' => 'contract_id is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $contractId = (int)$data['contract_id'];

        try {
            $result = $this->syncService->syncContract($contractId);

            if ($result->getStatus() === \App\Part3Api\Entity\ContractSync::STATUS_SUCCESS) {
                return new JsonResponse([
                    'status' => 'success',
                    'erse_id' => $result->getErseExternalId()
                ], Response::HTTP_CREATED);
            }

            // if failed we might return details or generic error
            return new JsonResponse([
                'status' => 'error',
                'error' => 'sync_failed',
                'details' => $result->getResponsePayload()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);

        } catch (ContractNotFoundException $e) {
            return new JsonResponse([
                'status' => 'error',
                'error' => 'contract_not_found'
            ], Response::HTTP_NOT_FOUND);
        }
    }
}
