<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\QuickReplyController;
use App\Http\Controllers\ClosingNoteController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NotificationLogController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\ManualController;

// ── Auth (guest only) ────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

// ── WhatsApp Webhook (public, no auth) ───────────────────────────────────────
Route::get('/webhook', [WhatsAppController::class, 'verifyWebhook'])->name('webhook.verify');
Route::post('/webhook', [WhatsAppController::class, 'handleWebhook'])->name('webhook.handle');

// ── Authenticated ────────────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // ── Superadmin (dueño del SaaS) ──────────────────────────────────────────
    Route::middleware('superadmin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/tenants', [\App\Http\Controllers\Admin\TenantsController::class, 'index'])->name('tenants.index');
        Route::post('/tenants', [\App\Http\Controllers\Admin\TenantsController::class, 'store'])->name('tenants.store');
        Route::put('/tenants/{tenant}', [\App\Http\Controllers\Admin\TenantsController::class, 'update'])->name('tenants.update');
        Route::delete('/tenants/{tenant}', [\App\Http\Controllers\Admin\TenantsController::class, 'destroy'])->name('tenants.destroy');
    });

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Contacts
    Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::put('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
    Route::get('/contacts/{contact}/chat', [ContactController::class, 'chat'])->name('contacts.chat');

    // Labels
    Route::get('/labels', [LabelController::class, 'index'])->name('labels.index');
    Route::post('/labels', [LabelController::class, 'store'])->name('labels.store');
    Route::put('/labels/{label}', [LabelController::class, 'update'])->name('labels.update');
    Route::delete('/labels/{label}', [LabelController::class, 'destroy'])->name('labels.destroy');
    Route::post('/labels/load-samples', [LabelController::class, 'loadSamples'])->name('labels.load-samples');

    // Chat
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('/chat/send', [ChatController::class, 'sendMessage'])->name('chat.send');
    Route::post('/chat/send-media', [ChatController::class, 'sendMedia'])->name('chat.send-media');
    Route::patch('/chat/conversations/{conversation}/assign', [ChatController::class, 'assign'])->name('chat.conversations.assign');
    Route::post('/chat/conversations/{conversation}/reopen', [ChatController::class, 'reopen'])->name('chat.conversations.reopen');
    Route::delete('/chat/conversations/{conversation}', [ChatController::class, 'destroy'])->name('chat.conversations.destroy');

    // Media files (bypasses storage symlink issues, requires auth)
    Route::get('/media/{path}', [MediaController::class, 'serve'])
        ->where('path', '.+')
        ->name('media.serve');

    // Templates (Plantillas Meta de WhatsApp)
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::post('/templates/sync', [TemplateController::class, 'sync'])->name('templates.sync');
    Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
    Route::patch('/templates/{template}/toggle', [TemplateController::class, 'toggleActive'])->name('templates.toggle');
    Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');

    // Staff
    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::post('/staff/invite', [StaffController::class, 'invite'])->name('staff.invite');
    Route::put('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
    Route::patch('/staff/{staff}/toggle', [StaffController::class, 'toggle'])->name('staff.toggle');
    Route::delete('/staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');

    // Teams
    Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
    Route::get('/teams/{team}/members', [TeamController::class, 'members'])->name('teams.members');
    Route::post('/teams/load-samples', [TeamController::class, 'loadSamples'])->name('teams.load-samples');
    Route::put('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
    Route::delete('/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');

    // Quick Replies
    Route::get('/quick-replies', [QuickReplyController::class, 'index'])->name('quick-replies.index');
    Route::post('/quick-replies', [QuickReplyController::class, 'store'])->name('quick-replies.store');
    Route::put('/quick-replies/{quickReply}', [QuickReplyController::class, 'update'])->name('quick-replies.update');
    Route::patch('/quick-replies/{quickReply}/toggle', [QuickReplyController::class, 'toggle'])->name('quick-replies.toggle');
    Route::post('/quick-replies/{quickReply}/duplicate', [QuickReplyController::class, 'duplicate'])->name('quick-replies.duplicate');
    Route::post('/quick-replies/load-samples', [QuickReplyController::class, 'loadSamples'])->name('quick-replies.load-samples');
    Route::delete('/quick-replies/{quickReply}', [QuickReplyController::class, 'destroy'])->name('quick-replies.destroy');

    // Closing Notes
    Route::get('/closing-notes', [ClosingNoteController::class, 'index'])->name('closing-notes.index');
    Route::post('/closing-notes', [ClosingNoteController::class, 'store'])->name('closing-notes.store');
    Route::put('/closing-notes/{closingNote}', [ClosingNoteController::class, 'update'])->name('closing-notes.update');
    Route::delete('/closing-notes/{closingNote}', [ClosingNoteController::class, 'destroy'])->name('closing-notes.destroy');
    Route::post('/closing-notes/{closingNote}/reopen', [ClosingNoteController::class, 'reopen'])->name('closing-notes.reopen');

    // Metrics
    Route::get('/metrics', [MetricsController::class, 'index'])->name('metrics.index');

    // Bitácora de avisos automáticos (factura generada / recordatorio de pago)
    Route::get('/notifications', [NotificationLogController::class, 'index'])->name('notifications.index');

    // Manual de Usuario
    Route::get('/manual', [ManualController::class, 'index'])->name('manual.index');

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile.update');
    Route::put('/settings/whatsapp', [SettingsController::class, 'updateWhatsApp'])->name('settings.whatsapp.update');
    Route::put('/settings/notifications', [SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');
    Route::post('/settings/whatsapp/test', [SettingsController::class, 'testWhatsAppConnection'])->name('settings.whatsapp.test');
    Route::get('/settings/ispwatch/status', [SettingsController::class, 'ispwatchStatus'])->name('settings.ispwatch.status');
    // La vinculación con ispwatch NO es editable desde la UI: se hace con
    // `php artisan tenant:link` por el admin del SaaS.
});
