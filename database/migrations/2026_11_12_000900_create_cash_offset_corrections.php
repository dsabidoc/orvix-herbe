<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_offset_corrections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cash_collection_cut_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_cut_id')->nullable()->unique()->constrained('cash_collection_cuts')->restrictOnDelete();
            $table->foreignId('cash_cut_offset_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('base_version');
            $table->string('kind');
            $table->string('status')->default('pending');
            $table->text('reason');
            $table->text('evidence');
            $table->json('input');
            $table->json('proposal');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        foreach (['cajas.offsets.correct-request', 'cajas.offsets.correct-resolve'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('cash_offset_corrections')->exists()) {
            throw new RuntimeException('No se puede eliminar el historial de correcciones de compensaciones.');
        }
        Schema::dropIfExists('cash_offset_corrections');
    }
};
