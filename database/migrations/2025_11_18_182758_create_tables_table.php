<?php

use App\Enums\TableStatus;
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
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('floor_plan_id')->constrained()->cascadeOnDelete();
            $table->string('name');          // e.g. "T1"
            $table->string('code')->unique(); // internal code
            $table->unsignedInteger('capacity')->default(2);
            $table->string('qr_token')->unique();
            $table->string('status')->default(TableStatus::FREE->value);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
