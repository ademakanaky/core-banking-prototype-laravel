<?php

/**
 * Bridge ramp adds two ramp_sessions fields that don't fit the existing shape:
 *
 *  - `deposit_instructions` (encrypted TEXT, holds JSON via Eloquent cast):
 *    Bridge onramp has no checkout URL. It returns an account number, routing
 *    number / IBAN, account holder name, and an optional memo the user must
 *    include in their wire. This is PII targeting the user, so it's encrypted
 *    at rest. Column is TEXT to fit Laravel encrypted-cast ciphertext.
 *
 *  - `source`: distinguishes user-initiated sessions (the user pulled a quote
 *    and confirmed) from Bridge-initiated sessions (the user wired fiat to
 *    their virtual account without a preceding POST /ramp/session, so we
 *    auto-created a session retroactively from the webhook). Drives different
 *    markup behavior — see ADR-0006.
 *
 * @see docs/BACKEND_HANDOVER_BRIDGE_RAMP.md §3.1, §4.1
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
        Schema::table('ramp_sessions', function (Blueprint $table): void {
            $table->text('deposit_instructions')->nullable()->after('metadata');
            $table->string('source', 32)->default('user_initiated')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ramp_sessions', function (Blueprint $table): void {
            $table->dropColumn(['deposit_instructions', 'source']);
        });
    }
};
