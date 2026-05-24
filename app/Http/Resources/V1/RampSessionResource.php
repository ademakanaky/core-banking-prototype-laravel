<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domain\Ramp\Providers\StripeCryptoOnrampProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\RampSession
 */
class RampSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $metadata = $this->metadata ?? [];

        return [
            'id'              => $this->id,
            'provider'        => $this->provider,
            'type'            => $this->type,
            'type_label'      => $this->type === 'on' ? 'Buy Crypto' : 'Sell Crypto',
            'fiat_currency'   => $this->fiat_currency,
            'fiat_amount'     => $this->fiat_amount,
            'crypto_currency' => $this->crypto_currency,
            'crypto_amount'   => $this->crypto_amount,
            'wallet_address'  => $this->wallet_address,
            'status'          => $this->status,
            'status_label'    => $this->resolveStatusLabel(),
            'checkout_url'    => $metadata['checkout_url'] ?? null,
            'created_at'      => $this->created_at->toIso8601String(),
            'updated_at'      => $this->updated_at->toIso8601String(),
        ];
    }

    /**
     * Resolve a human-readable status label.
     * For Stripe Crypto Onramp sessions, use the label from webhook metadata
     * if available. Historic rows may carry the legacy provider name; we
     * match both.
     */
    private function resolveStatusLabel(): string
    {
        $metadata = $this->metadata ?? [];

        $isStripeOnramp = in_array(
            $this->provider,
            [StripeCryptoOnrampProvider::PROVIDER_NAME, StripeCryptoOnrampProvider::LEGACY_PROVIDER_NAME],
            true,
        );

        if ($isStripeOnramp && isset($metadata['stripe_status_label'])) {
            return (string) $metadata['stripe_status_label'];
        }

        return match ($this->status) {
            'pending'    => 'Waiting for payment',
            'processing' => 'Processing',
            'completed'  => 'Completed',
            'failed'     => 'Payment failed',
            'expired'    => 'Session expired',
            default      => ucfirst($this->status),
        };
    }
}
