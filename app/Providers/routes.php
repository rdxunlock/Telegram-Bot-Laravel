<?php

use App\Http\Controllers\IndexController;
use Illuminate\Support\Facades\Route;

Route::group(
    [
    ],
    function () {
        Route::get('/', [IndexController::class, 'index']);
    }
);

Route::group(
    [
        'prefix' => 'api',
    ],
    function () {
        Route::post("/webhook", [IndexController::class, 'webhook']);
        Route::post("/sendMessage", [IndexController::class, 'sendMessage']);
    }
);
