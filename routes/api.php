<?php

use App\Http\Controllers\DataController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::resource('data', DataController::class);
Route::get('sum', [DataController::class, 'sum']);
Route::put('setlunas/{id}', [DataController::class, 'setLunas']);
Route::post('/pin-verify', [DataController::class, 'pinVerified']);
Route::post('/lockscreen', [DataController::class, 'lockscreen']);
Route::post('recap', [DataController::class, 'recapData']);
