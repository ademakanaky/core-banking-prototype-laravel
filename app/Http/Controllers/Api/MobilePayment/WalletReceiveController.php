<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\MobilePayment;

use App\Domain\MobilePayment\Enums\PaymentAsset;
use App\Domain\MobilePayment\Enums\PaymentNetwork;
use App\Domain\MobilePayment\Services\ReceiveAddressService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class WalletReceiveController extends Controller
{
    public function __construct(
        private readonly ReceiveAddressService $receiveAddressService,
    ) {
    }

    /**
     * Get a receive address for the authenticated user.
     *
     * GET /v1/wallet/receive?asset=USDC&network=SOLANA
     */
    #[OA\Get(
        path: '/api/v1/wallet/receive',
        operationId: 'mobilePaymentWalletReceive',
        summary: 'Get a receive address for the authenticated user',
        description: 'Returns a wallet receive address for the specified asset and network. The address can be used to receive payments from external wallets or exchanges.',
        tags: ['Mobile Payments'],
        security: [['sanctum' => []]],
        parameters: [
        new OA\Parameter(name: 'asset', in: 'query', required: true, description: 'Payment asset (USDC or USDT)', schema: new OA\Schema(type: 'string', enum: ['USDC', 'USDT'], example: 'USDC')),
        new OA\Parameter(name: 'network', in: 'query', required: true, description: 'Payment network', schema: new OA\Schema(type: 'string', enum: ['SOLANA', 'TRON'], example: 'SOLANA')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Receive address',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'address', type: 'string', example: '7xKXtg2CW87d97TXJSDpbD5jBkheTqA83TZRuJosgAsU'),
        new OA\Property(property: 'network', type: 'string', example: 'SOLANA'),
        new OA\Property(property: 'asset', type: 'string', example: 'USDC'),
        new OA\Property(property: 'qr_uri', type: 'string', example: 'solana:7xKXtg...?spl-token=USDC'),
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
        description: 'Validation error (invalid asset or network)',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'error', type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string', example: 'VALIDATION_ERROR'),
        new OA\Property(property: 'message', type: 'string', example: 'The given data was invalid.'),
        ]),
        ])
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'asset'   => ['required', 'string', 'in:' . implode(',', PaymentAsset::values())],
            'network' => ['required', 'string', 'in:SOLANA,TRON'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $network = PaymentNetwork::from($request->input('network'));
        $asset = PaymentAsset::from($request->input('asset'));

        $data = $this->receiveAddressService->getReceiveAddress(
            $user->id,
            $network,
            $asset,
        );

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }
}
