<?php

/**
 * Bridge.xyz customer record — 1:1 with users, holds the per-customer state we
 * need to drive the ramp + KYC surface without round-tripping to Bridge on
 * every read.
 *
 * Schema is partitioned from `users.kyc_status` (which is Ondato/TrustCert
 * KYC) per the §7.5 invariant in docs/BACKEND_HANDOVER_BRIDGE_RAMP.md —
 * Ondato and Bridge KYC datasets must never be conflated.
 *
 * `virtual_account_details` carries IBAN / routing / account number and is
 * encrypted at the model layer via an encrypted-JSON Eloquent cast (column is
 * TEXT to fit the ciphertext).
 *
 * `developer_fee_bps` mirrors the Bridge-side per-customer default. Updated
 * by the SubscriptionTierChanged handler that PATCHes Bridge whenever a user
 * upgrades/downgrades. See docs/adr/0006-bridge-developer-fees-as-markup-mechanism.md.
 *
 * @see docs/BACKEND_HANDOVER_BRIDGE_RAMP.md §3.3
 * @see docs/adr/0005-bridge-xyz-over-stripe-crypto-onramp.md
 * @see docs/adr/0006-bridge-developer-fees-as-markup-mechanism.md
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('bridge_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('bridge_customer_id', 64)->unique();

            $table->string('kyc_status', 16)->default('not_started');
            $table->text('kyc_link_url')->nullable();
            $table->timestamp('kyc_link_expires_at')->nullable();

            $table->string('virtual_account_id', 64)->nullable();
            $table->text('virtual_account_details')->nullable();
            $table->json('supported_rails')->nullable();

            $table->unsignedSmallInteger('developer_fee_bps')->default(75);

            $table->timestamps();

            $table->index('kyc_status');
            $table->index('virtual_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_customers');
    }
};
