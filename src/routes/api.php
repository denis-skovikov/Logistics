<?php

use App\Http\Controllers\HoldController;
use App\Http\Controllers\SlotController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/slots', [SlotController::class, 'index']);
    Route::post('/slots/{slotId}/hold', [HoldController::class, 'store']);
    Route::post('/holds/{holdId}/confirm', [HoldController::class, 'confirm']);
});
