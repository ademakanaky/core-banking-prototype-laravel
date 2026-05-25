<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Account\Models\BlockchainAddress;
use App\Domain\Compliance\Kyc\Enums\KycPurpose;
use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use App\Domain\Compliance\Kyc\Registries\KycProviderRouter;
use App\Domain\Compliance\Kyc\Services\BridgePostKycHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use RuntimeException;

/**
 * Bridge.xyz setup endpoints — KYC + virtual-account provisioning surface.
 *
 * Distinct from /api/v1/ramp/* (which handles per-session quotes + transfers)
 * because Bridge setup is a per-user, one-time ceremony that happens before
 * any ramp session can be created. Mobile uses these endpoints from the
 * "Set up bank transfers" entry in profile + JIT from the ramp Buy/Sell flow.
 *
 * §3.3 of docs/BACKEND_HANDOVER_BRIDGE_RAMP.md.
 */
class BridgeSetupController extends Controller
{
    public function __construct(
        private readonly KycProviderRouter $router,
        private readonly BridgePostKycHandler $postKyc,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/user/bridge-setup-status',
        operationId: 'v1UserBridgeSetupStatus',
        tags: ['Bridge Setup'],
        summary: 'Get Bridge.xyz KYC + virtual account setup status for the authenticated user',
        description: 'Reads from local DB cache populated by Bridge webhooks; never hits Bridge synchronously.',
        security: [['sanctum' => []]],
    )]
    #[OA\Response(
        response: 200,
        description: 'Bridge setup state',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'kycStatus', type: 'string', enum: ['not_started', 'pending', 'approved', 'rejected'], example: 'not_started'),
        new OA\Property(property: 'virtualAccountReady', type: 'boolean', example: false),
        new OA\Property(property: 'supportedRails', type: 'array', items: new OA\Items(type: 'string', enum: ['ach', 'sepa', 'sepa_instant'])),
        ])
    )]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $provider = $this->router->resolve(KycPurpose::RAMP);
        $providerStatus = $provider->getStatus($user->id);

        $customer = BridgeCustomer::where('user_id', $user->id)->first();

        return response()->json([
            'kycStatus'           => $providerStatus['status'],
            'virtualAccountReady' => $customer !== null && $customer->hasVirtualAccount(),
            'supportedRails'      => $customer === null ? [] : ($customer->supported_rails ?? []),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/user/bridge-kyc-link',
        operationId: 'v1UserBridgeKycLink',
        tags: ['Bridge Setup'],
        summary: 'Issue a hosted KYC link the user opens in an in-app browser',
        description: 'Lazily provisions the Bridge customer on first call (idempotent via Idempotency-Key: bridge_customer:{user_id}), then returns a hosted KYC link URL.',
        security: [['sanctum' => []]],
    )]
    #[OA\Response(
        response: 200,
        description: 'Hosted KYC link',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'url', type: 'string', format: 'uri', example: 'https://kyc.bridge.xyz/abc123'),
        new OA\Property(property: 'expiresAt', type: 'string', format: 'date-time', nullable: true),
        ])
    )]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 409, description: 'KYC already approved or in progress')]
    #[OA\Response(response: 501, description: 'Provider implementation deferred')]
    public function kycLink(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $existing = BridgeCustomer::where('user_id', $user->id)->first();
        if ($existing?->isKycApproved() === true) {
            return response()->json([
                'error'   => 'KYC_ALREADY_APPROVED',
                'message' => 'Bridge KYC is already approved for this user.',
            ], 409);
        }

        $provider = $this->router->resolve(KycPurpose::RAMP);

        try {
            $url = $provider->getHostedLink($user->id, KycPurpose::RAMP);
        } catch (RuntimeException $e) {
            // BridgeKycProvider currently throws "deferred to BridgeProvider PR" — surface
            // as 501 so mobile can detect the not-yet-implemented state distinctly.
            return response()->json([
                'error'   => 'PROVIDER_NOT_IMPLEMENTED',
                'message' => $e->getMessage(),
            ], 501);
        }

        $expiresAt = BridgeCustomer::where('user_id', $user->id)->value('kyc_link_expires_at');

        return response()->json([
            'url'       => $url,
            'expiresAt' => $expiresAt?->toIso8601String(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/user/bridge-va-provision',
        operationId: 'v1UserBridgeVaProvision',
        tags: ['Bridge Setup'],
        summary: 'Trigger Bridge virtual account provisioning for the authenticated user',
        description: 'Idempotent retry path for the ramp screen: if KYC is approved + a Polygon address exists + no VA yet, calls Bridge to provision one. Mobile calls this on screen mount when bridge-setup-status reports kycStatus=approved && !virtualAccountReady.',
        security: [['sanctum' => []]],
    )]
    #[OA\Response(
        response: 200,
        description: 'Current VA state after the provisioning attempt. virtualAccountReady=false means Bridge is still processing OR the call failed (check /bridge-setup-status again, listen for bridge.virtual_account.ready WS event).',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'virtualAccountReady', type: 'boolean', example: true),
        new OA\Property(property: 'supportedRails', type: 'array', items: new OA\Items(type: 'string', enum: ['ach', 'sepa', 'sepa_instant'])),
        ])
    )]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 409, description: 'KYC not approved, no Polygon address, or VA already provisioned')]
    public function provisionVirtualAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $customer = BridgeCustomer::where('user_id', $user->id)->first();

        if ($customer === null || ! $customer->isKycApproved()) {
            return response()->json([
                'error'   => 'KYC_NOT_APPROVED',
                'message' => 'Complete Bridge KYC before provisioning a virtual account.',
            ], 409);
        }

        if ($customer->hasVirtualAccount()) {
            return response()->json([
                'error'   => 'VA_ALREADY_PROVISIONED',
                'message' => 'A virtual account is already provisioned for this user.',
            ], 409);
        }

        $hasPolygonAddress = BlockchainAddress::where('user_uuid', $user->uuid)
            ->where('chain', 'polygon')
            ->exists();

        if (! $hasPolygonAddress) {
            return response()->json([
                'error'   => 'NO_POLYGON_ADDRESS',
                'message' => 'Register a Polygon wallet address before provisioning a virtual account.',
            ], 409);
        }

        // Idempotent at the Bridge layer via Idempotency-Key=bridge_va:{userId}.
        // Failures are swallowed + logged inside the handler; we surface the
        // resulting state via a fresh read of bridge_customers.
        $this->postKyc->tryProvisionVirtualAccount($customer);

        $customer->refresh();

        return response()->json([
            'virtualAccountReady' => $customer->hasVirtualAccount(),
            'supportedRails'      => $customer->supported_rails ?? [],
        ]);
    }
}
