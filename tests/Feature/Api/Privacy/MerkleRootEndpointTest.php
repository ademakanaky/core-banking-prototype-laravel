<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Privacy;

use App\Domain\Privacy\Contracts\MerkleTreeServiceInterface;
use App\Domain\Privacy\Services\DemoMerkleTreeService;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the /api/v1/privacy/merkle-root endpoint behaviour for both demo
 * (happy path) and production (provider-disabled) bindings, including the
 * mobile-friendly ?chain_id= alias.
 */
class MerkleRootEndpointTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->user = User::factory()->create();
    }

    public function test_it_returns_200_with_the_merkle_root_in_demo_mode(): void
    {
        $this->app->bind(MerkleTreeServiceInterface::class, DemoMerkleTreeService::class);

        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/v1/privacy/merkle-root?network=polygon');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'root',
                    'network',
                    'leaf_count',
                    'tree_depth',
                    'block_number',
                    'synced_at',
                ],
            ])
            ->assertJsonPath('data.network', 'polygon');
    }

    public function test_it_returns_503_err_privacy_310_when_the_production_provider_throws(): void
    {
        $this->bindThrowingMerkleService();

        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/v1/privacy/merkle-root?network=polygon');

        $response->assertStatus(503)
            ->assertExactJson([
                'error' => [
                    'code'    => 'ERR_PRIVACY_310',
                    'message' => 'Privacy pool is not available on this deployment.',
                ],
            ]);

        // No stack trace, file path, or implementation detail leaks.
        $payload = $response->getContent();
        $this->assertIsString($payload);
        $this->assertStringNotContainsString('not implemented', $payload);
        $this->assertStringNotContainsString('Trace', $payload);
        $this->assertStringNotContainsString('MerkleTreeService', $payload);
    }

    public function test_it_accepts_chain_id_as_well_as_network_and_gives_the_same_503_when_the_provider_is_disabled(): void
    {
        $this->bindThrowingMerkleService();

        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $networkResponse = $this->getJson('/api/v1/privacy/merkle-root?network=polygon');
        $chainIdResponse = $this->getJson('/api/v1/privacy/merkle-root?chain_id=polygon');

        $networkResponse->assertStatus(503)
            ->assertJsonPath('error.code', 'ERR_PRIVACY_310');

        $chainIdResponse->assertStatus(503)
            ->assertJsonPath('error.code', 'ERR_PRIVACY_310')
            ->assertJsonPath(
                'error.message',
                'Privacy pool is not available on this deployment.',
            );
    }

    public function test_it_returns_400_for_missing_network_param(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/v1/privacy/merkle-root');

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'ERR_PRIVACY_306');
    }

    /**
     * Binds a Mockery double for MerkleTreeServiceInterface that throws the
     * same RuntimeException the real production binding raises when the
     * provider isn't configured for this deployment.
     */
    private function bindThrowingMerkleService(): void
    {
        $mock = Mockery::mock(MerkleTreeServiceInterface::class);
        $mock->shouldReceive('getMerkleRoot')
            ->andThrow(new RuntimeException(
                'Production Merkle tree sync not implemented. Use DemoMerkleTreeService for development.'
            ));

        $this->app->instance(MerkleTreeServiceInterface::class, $mock);
    }
}
