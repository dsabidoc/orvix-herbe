<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 100);
            $table->string('kind', 10);
            $table->char('currency', 3)->default('MXN');
            $table->boolean('loans_enabled')->default(false);
            $table->boolean('naboo_enabled')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('cash_account_user', function (Blueprint $table): void {
            $table->foreignId('cash_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['cash_account_id', 'user_id']);
        });
        Schema::create('cash_operator_user', function (Blueprint $table): void {
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['operator_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_operator_user');
        Schema::dropIfExists('cash_account_user');
        Schema::dropIfExists('cash_accounts');
    }
};
