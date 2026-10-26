<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WatchController;
use Illuminate\Support\Facades\Route;

// No authentication: every route is public to whoever can reach the app.
Route::get('/', DashboardController::class)->name('home');
Route::get('dashboard', DashboardController::class)->name('dashboard');

Route::get('connections', [ConnectionController::class, 'index'])->name('connections.index');
Route::post('connections/mywhoosh', [ConnectionController::class, 'connectMyWhoosh'])->name('connections.mywhoosh.connect');
Route::delete('connections/mywhoosh', [ConnectionController::class, 'disconnectMyWhoosh'])->name('connections.mywhoosh.disconnect');
Route::post('connections/garmin', [ConnectionController::class, 'connectGarmin'])->name('connections.garmin.connect');
Route::delete('connections/garmin', [ConnectionController::class, 'disconnectGarmin'])->name('connections.garmin.disconnect');

Route::get('watches', [WatchController::class, 'index'])->name('watches.index');
Route::post('watches', [WatchController::class, 'select'])->name('watches.select');

Route::get('activities', [ActivityController::class, 'index'])->name('activities.index');
Route::post('activities/fetch', [ActivityController::class, 'fetch'])->name('activities.fetch');
Route::post('activities/{activity}/sync', [ActivityController::class, 'sync'])->name('activities.sync');
Route::post('activities/{activity}/fix', [ActivityController::class, 'fix'])->name('activities.fix');
Route::get('activities/{activity}/download', [ActivityController::class, 'download'])->name('activities.download');
Route::delete('activities/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');
