<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_movements', function (Blueprint $table) {
            $table->foreignId('target_installment_id')
                ->nullable()
                ->after('loan_id')
                ->constrained('installments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('collection_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_installment_id');
        });
    }
};
