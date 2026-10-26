<?php

use App\Http\Controllers\Admin\MonitorMaintenanceController;
use App\Http\Controllers\Admin\MonitorRecipientController;
use App\Http\Controllers\Admin\MonitorServiceController;
use App\Http\Controllers\StatusController;
use App\Http\Middleware\EnsureMonitorAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/', [StatusController::class, 'index'])->name('home');
Route::get('/api/status', [StatusController::class, 'show']);

Route::middleware(['auth', 'verified', EnsureMonitorAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('monitors', [MonitorServiceController::class, 'index'])->name('monitors.index');
    Route::post('monitors', [MonitorServiceController::class, 'store'])->name('monitors.store');
    Route::put('monitors/{monitor}', [MonitorServiceController::class, 'update'])->name('monitors.update');
    Route::post('monitors/{monitor}/disable', [MonitorServiceController::class, 'disable'])->name('monitors.disable');
    Route::post('monitors/{monitor}/enable', [MonitorServiceController::class, 'enable'])->name('monitors.enable');
    Route::get('recipients', [MonitorRecipientController::class, 'index'])->name('recipients.index');
    Route::post('recipients', [MonitorRecipientController::class, 'store'])->name('recipients.store');
    Route::put('recipients/{recipient}', [MonitorRecipientController::class, 'update'])->name('recipients.update');
    Route::post('recipients/{recipient}/disable', [MonitorRecipientController::class, 'disable'])->name('recipients.disable');
    Route::post('recipients/{recipient}/enable', [MonitorRecipientController::class, 'enable'])->name('recipients.enable');
    Route::post('recipients/{recipient}/test', [MonitorRecipientController::class, 'test'])->name('recipients.test');
    Route::get('maintenance', [MonitorMaintenanceController::class, 'index'])->name('maintenance.index');
    Route::post('maintenance', [MonitorMaintenanceController::class, 'store'])->name('maintenance.store');
    Route::put('maintenance/{maintenance}', [MonitorMaintenanceController::class, 'update'])->name('maintenance.update');
    Route::post('maintenance/{maintenance}/cancel', [MonitorMaintenanceController::class, 'cancel'])->name('maintenance.cancel');
    Route::post('maintenance/{maintenance}/finish', [MonitorMaintenanceController::class, 'finish'])->name('maintenance.finish');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
