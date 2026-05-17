<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Wallet;

use App\Domain\Wallet\Models\WalletSendRecord;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Coverage for GET /api/v1/wallet/transactions/by-intent/{intentId} — the
 * per-intent polling endpoint mobile uses on the send-confirmation screen to
 * learn a send's terminal outcome (confirmed vs failed).
 */
class WalletTransactionByIntentTest extends TestCase
{
    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token', ['read', 'write', 'delete'])->plainTextToken;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeRecord(array $overrides = []): WalletSendRecord
    {
        return WalletSendRecord::create(array_merge([
            'public_id'         => 'pi_send_' . uniqid(),
            'user_id'           => $this->user->id,
            'network'           => 'solana',
            'asset'             => 'USDT',
            'amount'            => '1.00000000',
            'sender_address'    => 'EfkncjQTojTB6m9DqoyBqizLLwZgLu1uwg3Y3FqE6f7Z',
            'recipient_address' => 'BobxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxV',
            'status'            => WalletSendRecord::STATUS_PENDING,
        ], $overrides));
    }

    public function test_returns_live_status_for_own_intent(): void
    {
        $record = $this->makeRecord([
            'status'       => WalletSendRecord::STATUS_SUBMITTED,
            'tx_hash'      => '5xRealSolanaSignature',
            'submitted_at' => now(),
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/wallet/transactions/by-intent/{$record->public_id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.intent_id', $record->public_id)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.tx_hash', '5xRealSolanaSignature')
            ->assertJsonPath('data.explorer_url', 'https://solscan.io/tx/5xRealSolanaSignature')
            ->assertJsonPath('data.error_code', null);
    }

    public function test_returns_error_details_for_a_failed_send(): void
    {
        $record = $this->makeRecord([
            'status'        => WalletSendRecord::STATUS_FAILED,
            'error_code'    => 'SOLANA_TX_FAILED',
            'error_message' => 'This transfer could not be completed on the Solana network.',
            'failed_at'     => now(),
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/wallet/transactions/by-intent/{$record->public_id}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_code', 'SOLANA_TX_FAILED')
            ->assertJsonPath('data.error_message', 'This transfer could not be completed on the Solana network.');
    }

    public function test_returns_404_for_unknown_intent(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/wallet/transactions/by-intent/pi_send_doesnotexist');

        $response->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INTENT_NOT_FOUND');
    }

    public function test_does_not_leak_another_users_intent(): void
    {
        $other = User::factory()->create();
        $record = WalletSendRecord::create([
            'public_id'         => 'pi_send_otheruser',
            'user_id'           => $other->id,
            'network'           => 'solana',
            'asset'             => 'USDT',
            'amount'            => '1.00000000',
            'sender_address'    => 'EfkncjQTojTB6m9DqoyBqizLLwZgLu1uwg3Y3FqE6f7Z',
            'recipient_address' => 'BobxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxV',
            'status'            => WalletSendRecord::STATUS_PENDING,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/wallet/transactions/by-intent/{$record->public_id}");

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'INTENT_NOT_FOUND');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/wallet/transactions/by-intent/pi_send_x')
            ->assertUnauthorized();
    }
}
