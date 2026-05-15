<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Wallet;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Regression coverage for the prepare endpoint's `network` validator.
 *
 * The quote endpoint accepts `network=SOLANA` because it validates against
 * `PaymentNetwork::values()`. Prepare used to be hardcoded
 * `in:solana,polygon,base,arbitrum,ethereum` which both rejected the
 * canonical `SOLANA` casing AND accepted strings that aren't valid enum
 * values. These tests lock in the post-fix contract: both endpoints share
 * the same case-sensitive `PaymentNetwork` allow-list.
 *
 * We only assert the *validator* — the full prepare flow needs a registered
 * sender address + Pimlico paymaster, etc. `assertJsonMissingValidationErrors`
 * is enough to prove the network rule accepts/rejects what it should.
 */
class WalletTransactionPrepareTest extends TestCase
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

    public function test_prepare_accepts_network_solana_uppercase(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/wallet/transactions/prepare', [
                'to'      => 'EfkncjQTojTB6m9DqoyBqizLLwZgLu1uwg3Y3FqE6f7Z',
                'token'   => 'USDC',
                'amount'  => '1.50',
                'network' => 'SOLANA',
                'quoteId' => 'q_test_solana_upper',
            ]);

        // Response may be 422 for downstream reasons (no sender address,
        // missing paymaster, etc.) — we only care that the `network` field
        // itself is not the reason.
        $response->assertJsonMissingValidationErrors(['network']);
    }

    public function test_prepare_accepts_network_polygon_lowercase(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/wallet/transactions/prepare', [
                'to'      => '0x742d35cc6634c0532925a3b844bc454e4438f44e',
                'token'   => 'USDC',
                'amount'  => '1.50',
                'network' => 'polygon',
                'quoteId' => 'q_test_polygon_lower',
            ]);

        $response->assertJsonMissingValidationErrors(['network']);
    }

    public function test_prepare_rejects_network_solana_lowercase(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/wallet/transactions/prepare', [
                'to'      => 'EfkncjQTojTB6m9DqoyBqizLLwZgLu1uwg3Y3FqE6f7Z',
                'token'   => 'USDC',
                'amount'  => '1.50',
                'network' => 'solana',
                'quoteId' => 'q_test_solana_lower',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['network']);
    }

    public function test_prepare_rejects_network_ethereum_uppercase(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/wallet/transactions/prepare', [
                'to'      => '0x742d35cc6634c0532925a3b844bc454e4438f44e',
                'token'   => 'USDC',
                'amount'  => '1.50',
                'network' => 'ETHEREUM',
                'quoteId' => 'q_test_ethereum_upper',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['network']);
    }

    public function test_prepare_rejects_unknown_network(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/wallet/transactions/prepare', [
                'to'      => '0x742d35cc6634c0532925a3b844bc454e4438f44e',
                'token'   => 'USDC',
                'amount'  => '1.50',
                'network' => 'FOOBAR',
                'quoteId' => 'q_test_foobar',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['network']);
    }
}
