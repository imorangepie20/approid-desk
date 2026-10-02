<?php

use App\Http\Controllers\Auth\InvitationAcceptanceController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyInvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\WorkRequestCommentController;
use App\Http\Controllers\WorkRequestController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;

Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['guest:web', 'throttle:password-reset'])
    ->name('password.email');

Route::middleware('guest:web')->group(function () {
    Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])
        ->name('invitations.accept');
    Route::post('invitations/{token}', [InvitationAcceptanceController::class, 'store'])
        ->middleware('throttle:invitation-accept')
        ->name('invitations.accept.store');
});

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::patch('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    Route::post('companies/{company}/invitations', [CompanyInvitationController::class, 'store'])
        ->name('companies.invitations.store');
    Route::delete('companies/{company}/invitations/{invitation}', [CompanyInvitationController::class, 'destroy'])
        ->name('companies.invitations.destroy');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');

    Route::get('requests', [WorkRequestController::class, 'index'])->name('requests.index');
    Route::get('requests/create', [WorkRequestController::class, 'create'])->name('requests.create');
    Route::post('requests', [WorkRequestController::class, 'store'])->name('requests.store');
    Route::get('requests/{workRequest}', [WorkRequestController::class, 'show'])->name('requests.show');
    Route::post('requests/{workRequest}/comments', [WorkRequestCommentController::class, 'store'])
        ->name('requests.comments.store');
});

require __DIR__.'/settings.php';
