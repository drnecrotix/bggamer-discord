<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscordAuthController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PublicBansController;
use App\Http\Controllers\ServerSettingsController;
use App\Http\Middleware\Staff;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/bans', [PublicBansController::class, 'index'])->name('bans');
Route::get('/auth/discord', [DiscordAuthController::class, 'redirect'])->name('login');
Route::get('/auth/discord/callback', [DiscordAuthController::class, 'callback']);
Route::middleware(Staff::class)->group(function () {
    Route::get('/admin', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', fn () => redirect()->route('dashboard'));
    Route::post('/logout', [DiscordAuthController::class, 'logout']);
});
Route::middleware(Staff::class.':moderator')->group(function () {
    Route::get('/admin/homepage', [PageController::class, 'edit'])->name('homepage.edit');
    Route::post('/admin/homepage', [PageController::class, 'update'])->middleware('throttle:10,1');
});
Route::middleware(Staff::class.':admin')->group(function () {
    Route::get('/admin/server', [ServerSettingsController::class, 'edit'])->name('server.edit');
    Route::post('/admin/server', [ServerSettingsController::class, 'update'])->middleware('throttle:5,10');
});
Route::post('/announcements', [DashboardController::class, 'announce'])->middleware([Staff::class.':admin', 'throttle:3,10']);
