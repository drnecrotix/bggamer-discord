<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscordAuthController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\OwnerAuthController;
use App\Http\Controllers\OwnerSettingsController;
use App\Http\Controllers\PublicBansController;
use App\Http\Controllers\ServerSettingsController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Middleware\OwnerOnly;
use App\Http\Middleware\Staff;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/bans', [PublicBansController::class, 'index'])->name('bans');
Route::get('/commands', [KnowledgeController::class, 'index'])->name('knowledge');
Route::get('/support', [SupportTicketController::class, 'create'])->name('support.create');
Route::post('/support', [SupportTicketController::class, 'store'])->middleware('throttle:3,60');
Route::post('/support/check', [SupportTicketController::class, 'lookup'])->middleware('throttle:5,10');
Route::get('/login', [OwnerAuthController::class, 'form'])->name('login');
Route::post('/login', [OwnerAuthController::class, 'login'])->middleware('throttle:5,10');
Route::get('/owner/login', fn () => redirect()->route('login'))->name('owner.login');
Route::post('/owner/login', [OwnerAuthController::class, 'login'])->middleware('throttle:5,10');
Route::get('/auth/discord', [DiscordAuthController::class, 'redirect'])->name('discord.login');
Route::get('/owner/discord/link', [DiscordAuthController::class, 'link'])->middleware(Staff::class)->name('owner.discord.link');
Route::get('/auth/discord/callback', [DiscordAuthController::class, 'callback']);
Route::middleware(Staff::class)->group(function () {
    Route::get('/admin', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', fn () => redirect()->route('dashboard'));
    Route::post('/owner/discord/unlink', [OwnerSettingsController::class, 'unlink'])->middleware('throttle:5,10');
    Route::post('/logout', [DiscordAuthController::class, 'logout']);
    Route::get('/admin/tickets', [SupportTicketController::class, 'index'])->name('tickets.index');
    Route::post('/admin/tickets/{ticket}', [SupportTicketController::class, 'update'])->middleware('throttle:20,1');
});
Route::middleware(Staff::class.':moderator')->group(function () {
    Route::get('/admin/homepage', [PageController::class, 'edit'])->name('homepage.edit');
    Route::post('/admin/homepage', [PageController::class, 'update'])->middleware('throttle:10,1');
    Route::get('/admin/knowledge', [KnowledgeController::class, 'manage'])->name('knowledge.manage');
    Route::post('/admin/knowledge', [KnowledgeController::class, 'store'])->middleware('throttle:10,1');
    Route::delete('/admin/knowledge/{item}', [KnowledgeController::class, 'destroy'])->middleware('throttle:10,1');
});
Route::middleware(Staff::class.':admin')->group(function () {
    Route::get('/admin/server', [ServerSettingsController::class, 'edit'])->name('server.edit');
    Route::post('/admin/server', [ServerSettingsController::class, 'update'])->middleware('throttle:5,10');
});
Route::post('/announcements', [DashboardController::class, 'announce'])->middleware([Staff::class.':admin', 'throttle:3,10']);

Route::middleware(OwnerOnly::class)->group(function () {
    Route::get('/owner/settings', [OwnerSettingsController::class, 'edit'])->name('owner.settings');
    Route::post('/owner/settings/discord', [OwnerSettingsController::class, 'updateDiscord'])->middleware('throttle:5,10');
    Route::post('/owner/settings/migrate', [OwnerSettingsController::class, 'migrate'])->middleware('throttle:1,10');
    Route::get('/owner/updates', [UpdateController::class, 'index'])->name('owner.updates');
    Route::post('/owner/updates/check', [UpdateController::class, 'check'])->middleware('throttle:3,10');
    Route::post('/owner/updates', [UpdateController::class, 'apply'])->middleware('throttle:1,10');
});
