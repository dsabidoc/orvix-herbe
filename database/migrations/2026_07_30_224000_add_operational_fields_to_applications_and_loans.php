<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->text('rejected_reason')->nullable()->after('approved_conditions');
            $table->timestamp('started_at')->nullable()->after('rejected_reason');
            $table->foreignId('loan_id')->nullable()->after('started_at')->constrained()->nullOnDelete();
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->string('settlement_reason')->nullable()->after('status');
            $table->timestamp('settled_at')->nullable()->after('settlement_reason');
            $table->foreignId('settled_by')->nullable()->after('settled_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn(['settlement_reason', 'settled_at']);
        });

        Schema::table('loan_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_id');
            $table->dropColumn(['rejected_reason', 'started_at']);
        });
    }
};
