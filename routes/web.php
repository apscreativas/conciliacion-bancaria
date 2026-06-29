<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/team-invitations/{token}', [App\Http\Controllers\TeamInvitationController::class, 'show'])->name('team-invitations.accept');
Route::post('/team-invitations/{token}/join', [App\Http\Controllers\TeamInvitationController::class, 'accept'])->name('team-invitations.join');

Route::get('/dashboard', [App\Http\Controllers\DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/upload/files', [App\Http\Controllers\FileUploadController::class, 'store'])->name('upload.store');

    // Team Management
    Route::resource('teams', App\Http\Controllers\TeamController::class)->only(['create', 'store', 'update']);
    Route::put('/current-team', [App\Http\Controllers\CurrentTeamController::class, 'update'])->name('current-team.update');
    Route::get('/teams/members', [App\Http\Controllers\TeamMemberController::class, 'index'])->name('teams.show');
    Route::post('/teams/members', [App\Http\Controllers\TeamMemberController::class, 'store'])->name('team-members.store');
    Route::delete('/teams/{team}/members/{user}', [App\Http\Controllers\TeamMemberController::class, 'destroy'])->name('team-members.destroy');
    Route::delete('/team-invitations/{invitation}', [App\Http\Controllers\TeamInvitationController::class, 'destroy'])->name('team-invitations.destroy');

    // Reconciliation Routes
    Route::get('/reconciliation', [App\Http\Controllers\ReconciliationController::class, 'index'])->name('reconciliation.index');
    Route::post('/reconciliation', [App\Http\Controllers\ReconciliationController::class, 'store'])->name('reconciliation.store');
    Route::get('/reconciliation/auto', [App\Http\Controllers\ReconciliationController::class, 'auto'])->name('reconciliation.auto');
    Route::post('/reconciliation/batch', [App\Http\Controllers\ReconciliationController::class, 'batch'])->name('reconciliation.batch');
    Route::delete('/reconciliation/{id}', [App\Http\Controllers\ReconciliationController::class, 'destroy'])->name('reconciliation.destroy');
    Route::delete('/reconciliation/group/{groupId}', [App\Http\Controllers\ReconciliationController::class, 'destroyGroup'])->name('reconciliation.group.destroy');
    Route::patch('/reconciliation/group/{groupId}/empresa', [App\Http\Controllers\ReconciliationController::class, 'updateGroupEmpresa'])->name('reconciliation.group.empresa.update');
    Route::get('/reconciliation/history', [App\Http\Controllers\ReconciliationController::class, 'history'])->name('reconciliation.history');
    Route::get('/reconciliation/status', [App\Http\Controllers\ReconciliationController::class, 'status'])->name('reconciliation.status');
    Route::get('/reconciliation/export', [App\Http\Controllers\ReconciliationController::class, 'export'])->middleware('throttle:10,1')->name('reconciliation.export');
    Route::get('/reconciliation/export/{id}/status', [App\Http\Controllers\ReconciliationController::class, 'checkExportStatus'])->name('reconciliation.export.status');
    Route::get('/reconciliation/export/{id}/download', [App\Http\Controllers\ReconciliationController::class, 'downloadExport'])->name('reconciliation.export.download');

    // Movimientos Routes
    Route::get('/movements', [App\Http\Controllers\MovimientoController::class, 'index'])->name('movements.index');
    Route::post('/movements/batch-destroy', [App\Http\Controllers\MovimientoController::class, 'batchDestroy'])->name('movements.batch-destroy');
    Route::get('/movements/{file}', [App\Http\Controllers\MovimientoController::class, 'show'])->name('movements.show');
    Route::delete('/movements/{file}', [App\Http\Controllers\MovimientoController::class, 'destroy'])->name('movements.destroy');

    // Facturas Routes
    Route::get('/invoices', [App\Http\Controllers\FacturaController::class, 'index'])->name('invoices.index');
    Route::post('/invoices/batch-destroy', [App\Http\Controllers\FacturaController::class, 'batchDestroy'])->name('invoices.batch-destroy');
    Route::delete('/invoices/{file}', [App\Http\Controllers\FacturaController::class, 'destroy'])->name('invoices.destroy');

    // Settings Routes
    Route::get('/settings/tolerance', [App\Http\Controllers\ToleranciaController::class, 'edit'])->name('settings.tolerance');
    Route::post('/settings/tolerance', [App\Http\Controllers\ToleranciaController::class, 'update'])->name('settings.tolerance.update');

    // Bank Formats Management
    Route::resource('bank-formats', \App\Http\Controllers\BankFormatController::class);
    Route::post('/bank-formats/preview', [\App\Http\Controllers\BankFormatController::class, 'preview'])->name('bank-formats.preview');
    Route::get('/api/bank-formats', [\App\Http\Controllers\BankFormatController::class, 'list'])->name('bank-formats.list');

    // Finanzas — Fase 0: dimensión empresa + catálogo de categorías (solo owner del team)
    Route::resource('settings/companies', \App\Http\Controllers\EmpresaController::class)->names('settings.companies')->except('show');
    Route::resource('settings/categories', \App\Http\Controllers\CategoriaController::class)->names('settings.categories')->except('show');
});

require __DIR__.'/auth.php';
