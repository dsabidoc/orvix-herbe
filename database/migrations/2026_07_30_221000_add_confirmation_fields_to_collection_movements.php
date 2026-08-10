<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_movements', function (Blueprint $table) {
            $table->foreignId('confirmed_by')->nullable()->after('registered_by')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('operated_on');
        });
    }

    public function down(): void
    {
        Schema::table('collection_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn('confirmed_at');
        });
    }
};
