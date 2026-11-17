<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_collection_cuts', function (Blueprint $table): void {
            $table->timestamp('custody_locked_at')->nullable();
        });
        Schema::create('cash_cut_receipts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cash_collection_cut_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_operation_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->date('received_on');
            $table->string('reference', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('cash_cut_offsets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_collection_cut_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_pending_loan_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->date('executed_on');
            $table->string('reference', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('cash_cut_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_collection_cut_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('action');
            $table->text('note')->nullable();
            $table->json('snapshot')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['cash_collection_cut_id', 'version']);
        });
        foreach (['cajas.receipts.record', 'cajas.offsets.execute', 'cajas.cuts.submit', 'cajas.cuts.review', 'cajas.cuts.approve-test'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_cut_reviews');
        Schema::dropIfExists('cash_cut_offsets');
        Schema::dropIfExists('cash_cut_receipts');
        Schema::table('cash_collection_cuts', fn (Blueprint $table) => $table->dropColumn('custody_locked_at'));
    }
};
