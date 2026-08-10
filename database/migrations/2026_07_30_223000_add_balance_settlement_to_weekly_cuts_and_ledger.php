<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_cuts', function (Blueprint $table) {
            $table->timestamp('balance_settled_at')->nullable()->after('confirmed_at');
            $table->foreignId('balance_settled_by')->nullable()->after('balance_settled_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('operator_ledger_entries', function (Blueprint $table) {
            $table->timestamp('settled_at')->nullable()->after('reason');
            $table->foreignId('settled_by')->nullable()->after('settled_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('operator_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn('settled_at');
        });

        Schema::table('weekly_cuts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('balance_settled_by');
            $table->dropColumn('balance_settled_at');
        });
    }
};
