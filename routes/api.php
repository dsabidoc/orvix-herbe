<?php

use App\Http\Controllers\CajasPortfolioCapitalController;
use App\Http\Controllers\CajasV1CustodyController;
use App\Http\Controllers\CajasV1PilotController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/cajas/capital-disponible', CajasPortfolioCapitalController::class);
Route::get('/v1/cajas/conciliaciones/{close}/esperado-financiero', CajasV1CustodyController::class)
    ->where('close', '[A-Za-z0-9_-]{1,80}');
Route::prefix('/v1/cajas')->controller(CajasV1PilotController::class)->group(function (): void {
    Route::get('/pagares', 'catalog');
    Route::post('/cortes/validaciones', 'validateCut');
    Route::post('/cortes', 'receive');
    Route::get('/cortes/{cut}/versiones/{version}', 'result')
        ->where(['cut' => '[A-Za-z0-9_-]{1,80}', 'version' => '[1-9][0-9]{0,9}']);
    Route::post('/cortes/{cut}/correcciones', 'correctPayment')
        ->where('cut', '[A-Za-z0-9_-]{1,80}');
    Route::get('/pagos-directos/{payment}', 'directPayment')
        ->where('payment', '[A-Za-z0-9_-]{1,80}');
    Route::post('/confirmaciones-custodia', 'confirmCustody');
    Route::get('/creditos-pendientes/{pendingCredit}', 'pendingCredit')
        ->where('pendingCredit', '[A-Za-z0-9_-]{1,80}');
    Route::post('/creditos-pendientes/{pendingCredit}/fondeo', 'fundPendingCredit')
        ->where('pendingCredit', '[A-Za-z0-9_-]{1,80}');
});
