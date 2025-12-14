<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();

            // Core Relationships
            $table->foreignId('table_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_waiter_id')->nullable()->constrained('users')->nullOnDelete();

            // Session Data & Status
            $table->string('session_code', 10)->unique(); // Use a unique short code for reference
            $table->string('started_by', 10)->default('client')->comment('client or waiter');
            $table->enum('status', [
                'waiting-confirmation', // Client placed order, waiter needs to confirm
                'active',               // Primary state: items being served/prepared
                'waiting-payment',      // Service is done, waiting for payment/cashier
                'closed',               // End of session, no more orders possible
            ])->default('waiting-confirmation');

            $table->dateTime('opened_at')->nullable();
            $table->dateTime('closed_at')->nullable();

            $table->timestamps(); // created_at (Session Start Time), updated_at

            $table->index(['table_id', 'status']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_sessions');
    }
};
