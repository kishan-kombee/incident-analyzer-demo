<?php

use App\Http\Controllers\IncidentController;
use App\Http\Middleware\EnsureJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/analyze', [IncidentController::class, 'analyze'])
    ->middleware(EnsureJsonResponse::class);

