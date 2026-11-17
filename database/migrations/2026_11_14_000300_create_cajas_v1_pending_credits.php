<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas_v1_pending_credits', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 80)->unique();
            $table->string('cut_id', 80);
            $table->unsignedInteger('cut_version');
            $table->foreignId('operator_id')->constrained()->restrictOnDelete();
            $table->string('offset_id', 80)->unique();
            $table->json('offset');
            $table->json('proposal');
            $table->json('observations');
            $table->json('reviewed_schedule')->nullable();
            $table->string('status', 24);
            $table->foreignId('loan_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cajas_v1_pending_credits');
    }
};
