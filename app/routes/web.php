<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\Administrator;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::middleware(['auth', ActiveAccount::class])->group(function () {
    Route::get('/', [FinanceController::class, 'dashboard']);
    Route::view('/account', 'account');
    Route::post('/account', [AuthController::class, 'password']);
    Route::get('/transactions', [FinanceController::class, 'transactions']);
    Route::get('/reports', [ReportController::class, 'index']);
    Route::middleware(Administrator::class)->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::post('/users/{user}/role', [UserController::class, 'role']);
        Route::get('/members', [MemberController::class, 'index']);
        Route::view('/members/create', 'member-form');
        Route::post('/members', [MemberController::class, 'save']);
        Route::get('/members/{member}/edit', [MemberController::class, 'edit']);
        Route::put('/members/{member}', [MemberController::class, 'save']);
        Route::delete('/members/{member}', [MemberController::class, 'delete']);
        Route::get('/transactions/{transaction}/edit', [FinanceController::class, 'edit']);
        Route::put('/transactions/{transaction}', [FinanceController::class, 'update']);
        Route::get('/transactions/create', [FinanceController::class, 'create']);
        Route::post('/transactions', [FinanceController::class, 'store']);
        Route::delete('/transactions/{transaction}', [FinanceController::class, 'void']);
        Route::get('/transactions/{transaction}/attachment', [FinanceController::class, 'attachment']);
        Route::get('/categories', [FinanceController::class, 'categories']);
        Route::post('/categories', [FinanceController::class, 'category']);
        Route::get('/closing', [FinanceController::class, 'closing']);
        Route::post('/closing', [FinanceController::class, 'close']);
        Route::get('/audit', [FinanceController::class, 'audit']);
        Route::get('/imports', [ImportController::class, 'index']);
        Route::post('/imports', [ImportController::class, 'preview']);
        Route::post('/imports/{id}/confirm',[ImportController::class, 'confirm']);
    });
});
