<?php

use IslamKabbary\AuditLog\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

// CP › Tools › Audit Log (names are prefixed with "statamic.cp.")
Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
Route::get('/audit-log/{id}', [AuditLogController::class, 'show'])->where('id', '[0-9]{20}-[a-z0-9]{4}')->name('audit-log.show');
