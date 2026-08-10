<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('status')->default('active')->after('password');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->boolean('force_password_change')->default(false)->after('last_login_at');
            $table->timestamp('locked_at')->nullable()->after('force_password_change');
            $table->index(['status', 'locked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status', 'locked_at']);
            $table->dropColumn([
                'phone',
                'status',
                'last_login_at',
                'force_password_change',
                'locked_at',
            ]);
        });
    }
};
