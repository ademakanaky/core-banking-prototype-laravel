<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Providers;

use App\Domain\Compliance\Kyc\Contracts\KycProviderInterface;
use App\Domain\Compliance\Kyc\Enums\KycPurpose;
use App\Domain\Compliance\Kyc\Models\BridgeCustomer;
use RuntimeException;

/**
 * Bridge.xyz adapter — skeleton.
 *
 * getName(), getStatus(), and getWebhookSignatureHeader() are real and read
 * from the bridge_customers table + config.
 *
 * getHostedLink(), getWebhookValidator(), and normalizeWebhookPayload()
 * throw a clear "not yet implemented" error pointing at the work that lands
 * in the BridgeProvider PR (handover §3.1, §3.3). They need a real HTTP
 * client against Bridge's REST API + verification of Bridge's exact webhook
 * signature scheme — shipping a guessed validator here would give false
 * confidence in tests.
 *
 * The interface contract is fully implemented so KycProviderRouter::resolve
 * works at runtime; callers that actually invoke the unimplemented methods
 * get a deterministic error rather than a silent failure.
 *
 * @see docs/BACKEND_HANDOVER_BRIDGE_RAMP.md §3.1, §3.3
 * @see docs/adr/0005-bridge-xyz-over-stripe-crypto-onramp.md
 * @see docs/adr/0006-bridge-developer-fees-as-markup-mechanism.md
 */
final class BridgeKycProvider implements KycProviderInterface
{
    public function getName(): string
    {
        return 'bridge';
    }

    public function getHostedLink(int $userId, KycPurpose $purpose, array $context = []): string
    {
        throw new RuntimeException(
            'BridgeKycProvider::getHostedLink not yet implemented. '
            . 'Lands in the BridgeProvider PR — see docs/BACKEND_HANDOVER_BRIDGE_RAMP.md §3.1. '
            . 'Must lazily create the Bridge customer on first call with '
            . 'Idempotency-Key: bridge_customer:{userId}, then POST /v0/customers/{id}/kyc_links.'
        );
    }

    public function getStatus(int $userId): array
    {
        $customer = BridgeCustomer::where('user_id', $userId)->first();

        if ($customer === null) {
            return [
                'status'   => 'not_started',
                'metadata' => [],
            ];
        }

        $status = match ($customer->kyc_status) {
            BridgeCustomer::KYC_APPROVED => 'approved',
            BridgeCustomer::KYC_PENDING  => 'pending',
            BridgeCustomer::KYC_REJECTED => 'rejected',
            default                      => 'not_started',
        };

        return [
            'status'   => $status,
            'metadata' => [
                'bridge_customer_id'    => $customer->bridge_customer_id,
                'virtual_account_ready' => $customer->hasVirtualAccount(),
                'supported_rails'       => $customer->supported_rails ?? [],
            ],
        ];
    }

    public function getWebhookValidator(): callable
    {
        return static function (string $rawBody, string $signatureHeader): bool {
            unset($rawBody, $signatureHeader);
            throw new RuntimeException(
                'BridgeKycProvider webhook signature verification not yet implemented. '
                . 'Lands in the BridgeProvider PR — verify Bridge\'s exact signature scheme '
                . 'against apidocs.bridge.xyz before shipping a validator.'
            );
        };
    }

    public function getWebhookSignatureHeader(): string
    {
        return 'Bridge-Signature';
    }

    public function normalizeWebhookPayload(array $payload): ?array
    {
        throw new RuntimeException(
            'BridgeKycProvider::normalizeWebhookPayload not yet implemented. '
            . 'Lands in the BridgeProvider PR — must handle customer.kyc_link_completed, '
            . 'customer.kyc_link_rejected, virtual_account.activity, transfer.completed, '
            . 'and transfer.failed events per handover §3.1.'
        );
    }
}
