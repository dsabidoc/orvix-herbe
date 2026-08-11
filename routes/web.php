<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvestorController;
use App\Http\Controllers\LoanApplicationController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LoanCreationController;
use App\Http\Controllers\LoanInvestmentController;
use App\Http\Controllers\LoanSettlementController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PortfolioBalanceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SimulatorController;
use App\Http\Controllers\WeeklyCutController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
    Route::get('/olvide-mi-contrasena', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/olvide-mi-contrasena', [AuthController::class, 'sendResetLink'])->name('password.email');
    Route::get('/restablecer-contrasena/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/restablecer-contrasena', [AuthController::class, 'resetPassword'])->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/prestamos', [LoanController::class, 'index'])->name('loans.index');
    Route::get('/cartera-y-saldos', [PortfolioBalanceController::class, 'index'])->name('portfolio-balances.index');
    Route::get('/cartera-y-saldos/exportar', [PortfolioBalanceController::class, 'export'])->name('portfolio-balances.export');
    Route::get('/prestamos/nuevo/crear', [LoanCreationController::class, 'create'])->name('loans.create');
    Route::post('/prestamos/nuevo/cotizar-redondeo', [LoanCreationController::class, 'quote'])->name('loans.quote-rounded');
    Route::post('/prestamos/nuevo/confirmar-redondeo', [LoanCreationController::class, 'confirmRounded'])->name('loans.confirm-rounded');
    Route::post('/prestamos/nuevo/crear', [LoanCreationController::class, 'store'])->name('loans.store');
    Route::get('/prestamos/{loan}/editar', [LoanController::class, 'edit'])->name('loans.edit');
    Route::put('/prestamos/{loan}', [LoanController::class, 'update'])->name('loans.update');
    Route::get('/prestamos/{loan}', [LoanController::class, 'show'])->name('loans.show');
    Route::post('/prestamos/{loan}/notas', [LoanController::class, 'storeNote'])->name('loans.notes.store');
    Route::post('/prestamos/{loan}/congelar', [LoanController::class, 'freeze'])->name('loans.freeze');
    Route::post('/prestamos/{loan}/reactivar', [LoanController::class, 'unfreeze'])->name('loans.unfreeze');
    Route::post('/prestamos/{loan}/factura', [LoanController::class, 'storeInvoice'])->name('loans.invoice.store');
    Route::post('/prestamos/{loan}/factura/mover', [LoanController::class, 'moveInvoice'])->name('loans.invoice.move');
    Route::post('/prestamos/{loan}/inversionistas', [LoanInvestmentController::class, 'store'])->name('loans.investments.store');
    Route::post('/prestamos/{loan}/liquidar', [LoanSettlementController::class, 'store'])->name('loans.settle');
    Route::post('/prestamos/{loan}/expediente', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/expedientes', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/expedientes/{document}/descargar', [DocumentController::class, 'download'])->name('documents.download');
    Route::delete('/expedientes/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::get('/inversionistas', [InvestorController::class, 'index'])->name('investors.index');
    Route::post('/inversionistas', [InvestorController::class, 'store'])->name('investors.store');
    Route::get('/inversionistas/{investor}', [InvestorController::class, 'show'])->name('investors.show');
    Route::post('/inversionistas/{investor}/retiros', [InvestorController::class, 'requestWithdrawal'])->name('investors.withdrawals.request');
    Route::post('/inversionistas/retiros/{withdrawal}/resolver', [InvestorController::class, 'processWithdrawal'])->name('investors.withdrawals.process');
    Route::post('/inversionistas/{investor}/retornos', [InvestorController::class, 'creditReturns'])->name('investors.returns.credit');
    Route::post('/inversionistas/{investor}/reinvertir', [InvestorController::class, 'reinvest'])->name('investors.reinvest');
    Route::post('/inversionistas/{investor}/retiro-directo', [InvestorController::class, 'directWithdrawal'])->name('investors.withdrawals.direct');
    Route::get('/cobranza', [CollectionController::class, 'index'])->name('collections.index');
    Route::post('/cobranza/letras/{installment}/pagado', [CollectionController::class, 'markPaid'])->name('collections.mark-paid');
    Route::post('/prestamos/{loan}/cobros', [PaymentController::class, 'store'])->name('payments.store');
    Route::post('/cobros/{movement}/confirmar', [PaymentController::class, 'confirm'])->name('payments.confirm');
    Route::post('/cobros/{movement}/regresar-pendiente', [PaymentController::class, 'reverse'])->name('payments.reverse');

    Route::get('/cortes', [WeeklyCutController::class, 'index'])->name('cuts.index');
    Route::post('/cortes', [WeeklyCutController::class, 'store'])->name('cuts.store');
    Route::get('/cortes/{cut}', [WeeklyCutController::class, 'show'])->name('cuts.show');
    Route::post('/cortes/{cut}/confirmar', [WeeklyCutController::class, 'confirm'])->name('cuts.confirm');
    Route::post('/cortes/{cut}/cerrar', [WeeklyCutController::class, 'close'])->name('cuts.close');
    Route::post('/cortes/{cut}/reabrir', [WeeklyCutController::class, 'reopen'])->name('cuts.reopen');
    Route::post('/cortes/{cut}/liquidar-saldo', [WeeklyCutController::class, 'settleBalance'])->name('cuts.settle-balance');

    Route::get('/clientes', [ClientController::class, 'index'])->name('clients.index');
    Route::get('/clientes/nuevo', [ClientController::class, 'create'])->name('clients.create');
    Route::post('/clientes', [ClientController::class, 'store'])->name('clients.store');
    Route::get('/clientes/{client}', [ClientController::class, 'show'])->name('clients.show');

    Route::get('/simulador', [SimulatorController::class, 'index'])->name('simulator.index');

    Route::get('/solicitudes', [LoanApplicationController::class, 'index'])->name('applications.index');
    Route::get('/solicitudes/nueva', [LoanApplicationController::class, 'create'])->name('applications.create');
    Route::post('/solicitudes', [LoanApplicationController::class, 'store'])->name('applications.store');
    Route::get('/solicitudes/{application}', [LoanApplicationController::class, 'show'])->name('applications.show');
    Route::post('/solicitudes/{application}/simular', [LoanApplicationController::class, 'simulate'])->name('applications.simulate');
    Route::post('/solicitudes/{application}/aprobar', [LoanApplicationController::class, 'approve'])->name('applications.approve');
    Route::post('/solicitudes/{application}/rechazar', [LoanApplicationController::class, 'reject'])->name('applications.reject');
    Route::post('/solicitudes/{application}/comenzar', [LoanApplicationController::class, 'start'])->name('applications.start');

    Route::get('/configuracion', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/configuracion/usuarios', [SettingsController::class, 'users'])->name('settings.users');
    Route::get('/configuracion/roles', [SettingsController::class, 'roles'])->name('settings.roles');
    Route::get('/configuracion/permisos', [SettingsController::class, 'permissions'])->name('settings.permissions');
    Route::post('/configuracion/usuarios', [SettingsController::class, 'storeUser'])->name('settings.users.store');
    Route::put('/configuracion/usuarios/{user}', [SettingsController::class, 'updateUser'])->name('settings.users.update');
    Route::post('/configuracion/usuarios/{user}/estado', [SettingsController::class, 'toggleUser'])->name('settings.users.toggle');
    Route::post('/configuracion/roles', [SettingsController::class, 'storeRole'])->name('settings.roles.store');
    Route::put('/configuracion/roles/{role}', [SettingsController::class, 'updateRole'])->name('settings.roles.update');
    Route::post('/configuracion/permisos', [SettingsController::class, 'storePermission'])->name('settings.permissions.store');
});
