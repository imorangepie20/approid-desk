<?php

use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\Auth\InvitationAcceptanceController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyInvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\EstimateDecisionController;
use App\Http\Controllers\MajorIncidentEventController;
use App\Http\Controllers\MajorIncidentRollbackController;
use App\Http\Controllers\MonthlyUsageController;
use App\Http\Controllers\MonthManagementController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationDeliveryController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\WorkLogController;
use App\Http\Controllers\WorkRequestCommentController;
use App\Http\Controllers\WorkRequestController;
use App\Http\Controllers\WorkRequestTransitionController;
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
    Route::get('usage', [MonthlyUsageController::class, 'index'])->name('usage.index');
    Route::get('usage/{contractMonth}', [MonthlyUsageController::class, 'show'])->name('usage.show');
    Route::get('usage/{contractMonth}/csv', [MonthlyUsageController::class, 'export'])->name('usage.export');
    Route::get('usage/{contractMonth}/manage', [MonthManagementController::class, 'show'])->name('usage.manage');
    Route::post('usage/{contractMonth}/close', [MonthManagementController::class, 'close'])->name('usage.close');
    Route::get('usage/{contractMonth}/adjustments/{entry}/create', [MonthManagementController::class, 'createAdjustment'])->name('usage.adjust.create');
    Route::post('usage/{contractMonth}/adjustments/{entry}', [MonthManagementController::class, 'storeAdjustment'])->name('usage.adjust.store');
    Route::post('notifications/{notification}/read', NotificationController::class)->name('notifications.read');
    Route::get('notification-deliveries', [NotificationDeliveryController::class, 'index'])
        ->name('notification-deliveries.index');
    Route::post('notification-deliveries/{notificationDelivery}/retry', [NotificationDeliveryController::class, 'retry'])
        ->name('notification-deliveries.retry');
    Route::get('attachments/{attachment}/download', AttachmentDownloadController::class)
        ->middleware('throttle:attachment-download')->name('attachments.download');

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
    Route::post('requests/{workRequest}/incident-events', [MajorIncidentEventController::class, 'store'])
        ->name('requests.incident-events.store');
    Route::post('requests/{workRequest}/rollbacks', [MajorIncidentRollbackController::class, 'store'])
        ->name('requests.rollbacks.store');
    Route::post('requests/{workRequest}/rollbacks/{rollback}/complete', [MajorIncidentRollbackController::class, 'complete'])
        ->name('requests.rollbacks.complete');
    Route::get('requests/{workRequest}/work-logs', [WorkLogController::class, 'index'])->name('requests.work-logs.index');
    Route::get('requests/{workRequest}/work-logs/create', [WorkLogController::class, 'create'])->name('requests.work-logs.create');
    Route::post('requests/{workRequest}/work-logs', [WorkLogController::class, 'store'])->name('requests.work-logs.store');
    Route::get('requests/{workRequest}/work-logs/{workLog}/edit', [WorkLogController::class, 'edit'])->name('requests.work-logs.edit');
    Route::patch('requests/{workRequest}/work-logs/{workLog}', [WorkLogController::class, 'update'])->name('requests.work-logs.update');
    Route::post('requests/{workRequest}/work-logs/{workLog}/confirm', [WorkLogController::class, 'confirm'])->name('requests.work-logs.confirm');
    Route::post('requests/{workRequest}/transition', WorkRequestTransitionController::class)->name('requests.transition');
    Route::get('requests/{workRequest}/estimates/create', [EstimateController::class, 'create'])->name('requests.estimates.create');
    Route::post('requests/{workRequest}/estimates', [EstimateController::class, 'store'])->name('requests.estimates.store');
    Route::get('requests/{workRequest}/estimates/{estimate}/preview', [EstimateController::class, 'preview'])->name('requests.estimates.preview');
    Route::post('requests/{workRequest}/estimates/{estimate}/submit', [EstimateController::class, 'submit'])->name('requests.estimates.submit');
    Route::get('requests/{workRequest}/estimates/{estimate}/decision', [EstimateDecisionController::class, 'show'])->name('requests.estimates.decision');
    Route::post('requests/{workRequest}/estimates/{estimate}/approve', [EstimateDecisionController::class, 'approve'])->name('requests.estimates.approve');
    Route::post('requests/{workRequest}/estimates/{estimate}/revision', [EstimateDecisionController::class, 'revision'])->name('requests.estimates.revision');
    Route::post('requests/{workRequest}/comments', [WorkRequestCommentController::class, 'store'])
        ->name('requests.comments.store');
});

require __DIR__.'/settings.php';
