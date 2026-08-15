<?php

use App\Http\Controllers\SlotController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/slots', [SlotController::class, 'index']);
});
