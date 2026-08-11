<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('user_id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->decimal('initial_capital', 15, 2)->default(0)->after('phone');
            $table->decimal('available_capital', 15, 2)->default(0)->after('initial_capital');
            $table->decimal('returned_capital_balance', 15, 2)->default(0)->after('available_capital');
            $table->decimal('generated_interest_balance', 15, 2)->default(0)->after('returned_capital_balance');
        });

        Schema::create('investor_capital_movements', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('investor_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('investment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('investor_withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('investor_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('submitted');
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        DB::table('investors')->orderBy('id')->each(function ($investor) {
            $parts = preg_split('/\s+/', trim($investor->name), 2) ?: [];
            DB::table('investors')
                ->where('id', $investor->id)
                ->update([
                    'first_name' => $parts[0] ?? $investor->name,
                    'last_name' => $parts[1] ?? '',
                ]);
        });

        foreach ([
            ['Alejandro', 'Patron', 'alejandro.inversionista@orvix.test', '350000.00'],
            ['Beatriz', 'Camara', 'beatriz.inversionista@orvix.test', '280000.00'],
            ['Carlos', 'Mendez', 'carlos.inversionista@orvix.test', '420000.00'],
        ] as [$firstName, $lastName, $email, $capital]) {
            if (DB::table('investors')->where('email', $email)->exists()) {
                continue;
            }

            DB::table('investors')->insert([
                'public_id' => (string) Str::ulid(),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => $firstName.' '.$lastName,
                'email' => $email,
                'initial_capital' => $capital,
                'available_capital' => $capital,
                'returned_capital_balance' => '0.00',
                'generated_interest_balance' => '0.00',
                'status' => 'active',
                'created_at' => now('America/Merida'),
                'updated_at' => now('America/Merida'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_withdrawal_requests');
        Schema::dropIfExists('investor_capital_movements');

        Schema::table('investors', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'initial_capital',
                'available_capital',
                'returned_capital_balance',
                'generated_interest_balance',
            ]);
        });
    }
};
