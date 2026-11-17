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
        Schema::table('cash_account_user', fn (Blueprint $table) => $table->boolean('can_adjust')->default(false));
        Schema::create('cash_cut_corrections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cash_collection_cut_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_cut_id')->nullable()->unique()->constrained('cash_collection_cuts')->restrictOnDelete();
            $table->unsignedInteger('base_version');
            $table->string('status')->default('pending');
            $table->string('kind');
            $table->unsignedBigInteger('target_id');
            $table->text('reason');
            $table->text('reference');
            $table->json('input');
            $table->json('proposal');
            $table->json('baseline');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('cash_cut_receipt_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_collection_cut_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_cut_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_cut_correction_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('cash_operation_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->date('operated_on');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('cash_cut_items', fn (Blueprint $table) => $table->foreignId('cash_cut_correction_id')->nullable()->unique()->constrained()->restrictOnDelete());
        foreach (['cajas.corrections.request', 'cajas.corrections.resolve'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('cash_cut_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('cash_cut_correction_id'));
        Schema::dropIfExists('cash_cut_receipt_adjustments');
        Schema::dropIfExists('cash_cut_corrections');
        Schema::table('cash_account_user', fn (Blueprint $table) => $table->dropColumn('can_adjust'));
    }
};
