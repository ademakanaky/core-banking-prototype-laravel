<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('device_reassignment_log', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->index();
            $table->unsignedBigInteger('previous_user_id')->nullable();
            $table->unsignedBigInteger('new_user_id');
            $table->boolean('had_bound_credentials')->default(false)
                ->comment('Whether the reassigned device had biometric/passkey credentials bound');
            $table->string('reason', 32)->comment('auto_push_only | operator_forced');
            $table->unsignedBigInteger('operator_id')->nullable()
                ->comment('Admin who forced the reassignment, when via mobile:reassign-device');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['new_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_reassignment_log');
    }
};
