<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domain\MobilePayment\Models\PaymentReceipt;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptPageTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function makeReceipt(array $overrides = []): PaymentReceipt
    {
        $user = User::factory()->create();

        return PaymentReceipt::create(array_merge([
            'public_id'      => 'rcpt_' . Str::random(24),
            'user_id'        => $user->id,
            'merchant_name'  => 'Coffee Shop',
            'amount'         => '12.50000000',
            'asset'          => 'USDC',
            'network'        => 'SOLANA',
            'tx_hash'        => '5xVgGJzqFVmLqnRkLGJpKfMoNu3ssKYfQYz4XbUmSqJxHfrx7c2yBz8tNvMwQs9X',
            'network_fee'    => '0.0003 USD',
            'share_token'    => Str::random(64),
            'transaction_at' => now()->subMinutes(3),
        ], $overrides));
    }

    public function test_renders_the_hosted_receipt_page_for_a_valid_share_token(): void
    {
        $receipt = $this->makeReceipt();

        $response = $this->get('/receipt/' . $receipt->share_token);

        $response->assertOk()
            ->assertSee('Payment Receipt')
            ->assertSee('Coffee Shop')
            ->assertSee('12.5')
            ->assertSee('USDC')
            ->assertSee($receipt->public_id);
    }

    public function test_returns_404_for_an_unknown_share_token(): void
    {
        $this->get('/receipt/' . Str::random(64))->assertNotFound();
    }

    public function test_shows_the_download_button_only_when_a_pdf_exists(): void
    {
        $withPdf = $this->makeReceipt(['pdf_path' => 'receipts/example.pdf']);
        $this->get('/receipt/' . $withPdf->share_token)->assertSee('Download PDF');

        $withoutPdf = $this->makeReceipt(['pdf_path' => null]);
        $this->get('/receipt/' . $withoutPdf->share_token)->assertDontSee('Download PDF');
    }
}
