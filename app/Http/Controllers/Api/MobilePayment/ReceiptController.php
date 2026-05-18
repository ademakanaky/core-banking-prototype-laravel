<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\MobilePayment;

use App\Domain\MobilePayment\Services\ReceiptService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptService $receiptService,
    ) {
    }

    /**
     * Generate a receipt for a transaction.
     *
     * POST /v1/transactions/{txId}/receipt
     */
    #[OA\Post(
        path: '/api/v1/transactions/{txId}/receipt',
        operationId: 'mobilePaymentGenerateReceipt',
        summary: 'Generate a receipt for a transaction',
        description: 'Generates a digital receipt for a confirmed transaction. Receipts can only be generated for transactions that have been confirmed on-chain.',
        tags: ['Mobile Payments'],
        security: [['sanctum' => []]],
        parameters: [
        new OA\Parameter(name: 'txId', in: 'path', required: true, description: 'Transaction ID', schema: new OA\Schema(type: 'string', example: 'tx_abc123')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Receipt generated',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'receiptId', type: 'string', example: 'rcpt_abc123'),
        new OA\Property(property: 'merchantName', type: 'string', example: 'Coffee Shop'),
        new OA\Property(property: 'amount', type: 'string', example: '25.50'),
        new OA\Property(property: 'asset', type: 'string', example: 'USDC'),
        new OA\Property(property: 'dateTime', type: 'string', format: 'date-time'),
        new OA\Property(property: 'networkFee', type: 'string', example: '0.01 USD'),
        new OA\Property(property: 'sharePayload', type: 'string', description: 'Public URL of the hosted receipt page', example: 'https://app.zelta.app/receipt/abc...'),
        new OA\Property(property: 'pdfUrl', type: 'string', nullable: true, description: 'Public URL of the downloadable receipt PDF (null if generation failed)', example: 'https://app.zelta.app/storage/receipts/abc....pdf'),
        ]),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'error', type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string', example: 'UNAUTHORIZED'),
        new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
        ]),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Receipt cannot be generated (transaction not confirmed)',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'error', type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string', example: 'RECEIPT_UNAVAILABLE'),
        new OA\Property(property: 'message', type: 'string', example: 'Receipt can only be generated for confirmed transactions.'),
        ]),
        ])
    )]
    public function store(Request $request, string $txId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $receipt = $this->receiptService->generateReceipt($txId, $user->id);

        if (! $receipt) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'RECEIPT_UNAVAILABLE',
                    'message' => 'Receipt can only be generated for confirmed transactions.',
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => $receipt->toApiResponse(),
        ]);
    }
}
