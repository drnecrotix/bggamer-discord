<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscordAuthController;
use App\Http\Middleware\Staff;
use Illuminate\Support\Facades\Route;

Route::view('/', 'login');
Route::get('/auth/discord', [DiscordAuthController::class, 'redirect'])->name('login');
Route::get('/auth/discord/callback', [DiscordAuthController::class, 'callback']);
Route::middleware(Staff::class)->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/announcements', [DashboardController::class, 'announce'])->middleware('throttle:3,10');
    Route::post('/logout', [DiscordAuthController::class, 'logout']);
});
