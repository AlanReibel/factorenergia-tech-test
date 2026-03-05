<?php

namespace App\Controller;

use App\Service\InvoiceService;
use App\Exception\ContractNotFoundException;
use App\Exception\TariffCalculationException;
use App\Exception\ExternalApiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends AbstractController
{
    private InvoiceService $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    /**
     * Create a new invoice for a contract
     * 
     * GET /invoices/create/{contractId}/{month}
     * Example: /invoices/create/123/2026-03
     */
    public function create(int $contractId, string $month): JsonResponse
    {
        try {
            $invoice = $this->invoiceService->createInvoice($contractId, $month);

            return new JsonResponse([
                'status' => 'success',
                'data' => [
                    'id' => $invoice->getId(),
                    'contract_id' => $invoice->getContractId(),
                    'billing_period' => $invoice->getBillingPeriod(),
                    'total_kwh' => $invoice->getTotalKwh(),
                    'total_amount' => $invoice->getTotalAmount(),
                    'status' => $invoice->getStatus()
                ]
            ], Response::HTTP_CREATED);

        } catch (ContractNotFoundException $e) {
            return new JsonResponse([
                'status' => 'error',
                'error' => 'contract_not_found',
                'message' => $e->getMessage()
            ], Response::HTTP_NOT_FOUND);

        } catch (TariffCalculationException $e) {
            return new JsonResponse([
                'status' => 'error',
                'error' => 'tariff_calculation_failed',
                'message' => 'Failed to calculate invoice due to tariff error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);

        } catch (ExternalApiException $e) {
            return new JsonResponse([
                'status' => 'error',
                'error' => 'external_api_failed',
                'message' => 'Failed to fetch pricing data from external API'
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
