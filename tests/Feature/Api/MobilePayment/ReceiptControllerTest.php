<?php

declare(strict_types=1);

namespace Tests\Feature\Api\MobilePayment;

use App\Domain\Commerce\Enums\MerchantStatus;
use App\Domain\Commerce\Models\Merchant;
use App\Domain\MobilePayment\Enums\ActivityItemType;
use App\Domain\MobilePayment\Enums\PaymentIntentStatus;
use App\Domain\MobilePayment\Models\ActivityFeedItem;
use App\Domain\MobilePayment\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptControllerTest extends TestCase
{
    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token', ['read', 'write', 'delete'])->plainTextToken;
    }

    public function test_generates_receipt_for_confirmed_solana_inbound_usdc_activity_feed_item_with_no_payment_intent(): void
    {
        $item = ActivityFeedItem::create([
            'id'            => (string) Str::uuid(),
            'user_id'       => $this->user->id,
            'activity_type' => ActivityItemType::TRANSFER_IN,
            'amount'        => '1.50000000',
            'asset'         => 'USDC',
            'network'       => 'SOLANA',
            'status'        => 'confirmed',
            'protected'     => false,
            'from_address'  => 'BobxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxV',
            'to_address'    => 'EfkncjQTojTB6m9DqoyBqizLLwZgLu1uwg3Y3FqE6f7Z',
            'occurred_at'   => now()->subMinutes(2),
            'metadata'      => [
                'tx_hash' => '5xVgGJzqFVmLqnRkLGJpKfMoNu3ssKYfQYz4XbUmSqJxHfrx7c2yBz8tNvMwQs9X',
                'fee_usd' => '0.0003',
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/transactions/{$item->id}/receipt");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'receiptId',
                    'merchantName',
                    'amount',
                    'asset',
                    'dateTime',
                    'networkFee',
                    'sharePayload',
                    'pdfUrl',
                ],
            ])
            ->assertJsonPath('data.merchantName', 'Received')
            ->assertJsonPath('data.asset', 'USDC')
            ->assertJsonPath('data.networkFee', '0.0003 USD');

        $this->assertSame('1.5', (string) (float) $response->json('data.amount'));

        // The share link points at the hosted receipt page, and a PDF was generated.
        $this->assertStringContainsString('/receipt/', (string) $response->json('data.sharePayload'));
        $this->assertNotNull($response->json('data.pdfUrl'));
        $this->assertStringContainsString('receipts/', (string) $response->json('data.pdfUrl'));
    }

    public function test_returns_422_for_pending_activity_feed_item(): void
    {
        $item = ActivityFeedItem::create([
            'id'            => (string) Str::uuid(),
            'user_id'       => $this->user->id,
            'activity_type' => ActivityItemType::TRANSFER_IN,
            'amount'        => '1.00000000',
            'asset'         => 'USDC',
            'network'       => 'SOLANA',
            'status'        => 'pending',
            'protected'     => false,
            'occurred_at'   => now(),
            'metadata'      => [
                'tx_hash' => '5xVgGJzqFVmLqnRkLGJpKfMoNu3ssKYfQYz4XbUmSqJxHfrx7c2yBz8tNvMwQs9X',
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/transactions/{$item->id}/receipt");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'RECEIPT_UNAVAILABLE');
    }

    public function test_preserves_existing_merchant_name_when_present_on_activity_feed_item(): void
    {
        $item = ActivityFeedItem::create([
            'id'            => (string) Str::uuid(),
            'user_id'       => $this->user->id,
            'activity_type' => ActivityItemType::TRANSFER_IN,
            'merchant_name' => 'Alice',
            'amount'        => '5.00000000',
            'asset'         => 'USDC',
            'network'       => 'SOLANA',
            'status'        => 'confirmed',
            'protected'     => false,
            'occurred_at'   => now()->subMinutes(1),
            'metadata'      => [
                'tx_hash' => '6yWhHKyqGWmMroSlMHKqLgNpOv4ttLZgRZa5YcVnTrKyIgsy8d3zCa9uOwNxRt0Y',
                'fee_usd' => '0.0005',
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/transactions/{$item->id}/receipt");

        $response->assertOk()
            ->assertJsonPath('data.merchantName', 'Alice');
    }

    public function test_resolves_payment_intent_backed_merchant_payments_via_reference(): void
    {
        $merchant = Merchant::create([
            'public_id'         => 'merchant_test_' . Str::random(8),
            'display_name'      => 'Coffee Shop',
            'icon_url'          => 'https://example.com/icon.png',
            'accepted_assets'   => ['USDC'],
            'accepted_networks' => ['SOLANA'],
            'status'            => MerchantStatus::ACTIVE,
        ]);

        $intent = PaymentIntent::create([
            'public_id'              => 'pi_' . Str::random(20),
            'user_id'                => $this->user->id,
            'merchant_id'            => $merchant->id,
            'asset'                  => 'USDC',
            'network'                => 'SOLANA',
            'amount'                 => '12.50',
            'status'                 => PaymentIntentStatus::CONFIRMED,
            'shield_enabled'         => false,
            'fees_estimate'          => ['nativeAsset' => 'SOL', 'amount' => '0.00004', 'usdApprox' => '0.01'],
            'required_confirmations' => 32,
            'expires_at'             => now()->addMinutes(15),
        ]);

        // Hydrate the on-chain bits the projector would normally set.
        $intent->tx_hash = '7zXiILzrHXnNspTmNILrMhOqPw5uuMahSAb6ZdWoUsLzJhtz9e4aDb0vPxOySu1Z';
        $intent->confirmed_at = now()->subMinutes(3);
        $intent->save();

        $item = ActivityFeedItem::create([
            'id'             => (string) Str::uuid(),
            'user_id'        => $this->user->id,
            'activity_type'  => ActivityItemType::MERCHANT_PAYMENT,
            'amount'         => '12.50',
            'asset'          => 'USDC',
            'network'        => 'SOLANA',
            'status'         => 'confirmed',
            'protected'      => false,
            'reference_type' => PaymentIntent::class,
            'reference_id'   => $intent->id,
            'occurred_at'    => now()->subMinutes(3),
            'metadata'       => [],
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/transactions/{$item->id}/receipt");

        $response->assertOk()
            ->assertJsonPath('data.merchantName', 'Coffee Shop')
            ->assertJsonPath('data.networkFee', '0.01 USD');

        $this->assertSame('12.5', (string) (float) $response->json('data.amount'));
    }

    public function test_receipt_generation_is_idempotent(): void
    {
        $item = ActivityFeedItem::create([
            'id'            => (string) Str::uuid(),
            'user_id'       => $this->user->id,
            'activity_type' => ActivityItemType::TRANSFER_IN,
            'amount'        => '2.00000000',
            'asset'         => 'USDC',
            'network'       => 'SOLANA',
            'status'        => 'confirmed',
            'protected'     => false,
            'occurred_at'   => now()->subMinutes(1),
            'metadata'      => [
                'tx_hash' => '8aYjMNasIYoOtqUnOJMsNiPrQx6vvNbiTBc7AfXpVtMaKiua0f5bEc1wQyPzTv2A',
                'fee_usd' => '0.0004',
            ],
        ]);

        $first = $this->withToken($this->token)
            ->postJson("/api/v1/transactions/{$item->id}/receipt");
        $first->assertOk();

        $second = $this->withToken($this->token)
            ->postJson("/api/v1/transactions/{$item->id}/receipt");
        $second->assertOk();

        $this->assertSame(
            $first->json('data.receiptId'),
            $second->json('data.receiptId'),
        );
    }
}
