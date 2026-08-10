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
use App\Http\Controllers\BotSettingsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\ManualController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\Brain;

// ── Auth (guest only) ────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

// ── Manual público (sin auth) ────────────────────────────────────────────────
// Mismo contenido que /manual, pero accesible con solo el link: sirve para
// pasárselo a un ISP que todavía no tiene cuenta. Es de solo lectura y no
// expone ningún dato del workspace.
Route::get('/ayuda', [ManualController::class, 'publicIndex'])->name('manual.public');

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

    // Chat — ver es para todos; escribir (enviar/asignar/notas/reabrir) requiere
    // agent o admin; borrar la conversación es solo admin.
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('/chat/send', [ChatController::class, 'sendMessage'])->middleware('role:agent')->name('chat.send');
    Route::post('/chat/send-media', [ChatController::class, 'sendMedia'])->middleware('role:agent')->name('chat.send-media');
    // Única vía para escribirle al cliente fuera de la ventana de 24h.
    Route::post('/chat/send-template', [ChatController::class, 'sendTemplate'])->middleware('role:agent')->name('chat.send-template');
    Route::patch('/chat/conversations/{conversation}/assign', [ChatController::class, 'assign'])->middleware('role:agent')->name('chat.conversations.assign');
    Route::post('/chat/conversations/{conversation}/notes', [ChatController::class, 'storeNote'])->middleware('role:agent')->name('chat.notes.store');
    Route::post('/chat/conversations/{conversation}/reopen', [ChatController::class, 'reopen'])->middleware('role:agent')->name('chat.conversations.reopen');
    Route::post('/chat/contacts/{contact}/labels', [ChatController::class, 'updateContactLabels'])->middleware('role:agent')->name('chat.contacts.labels');
    Route::delete('/chat/conversations/{conversation}', [ChatController::class, 'destroy'])->middleware('role:admin')->name('chat.conversations.destroy');

    // Media files (bypasses storage symlink issues, requires auth)
    Route::get('/media/{path}', [MediaController::class, 'serve'])
        ->where('path', '.+')
        ->name('media.serve');

    // Templates (Plantillas Meta de WhatsApp)
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::post('/templates/{template}/submit', [TemplateController::class, 'submitToMeta'])->name('templates.submit');
    Route::post('/templates/sync', [TemplateController::class, 'sync'])->name('templates.sync');
    Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
    Route::patch('/templates/{template}/toggle', [TemplateController::class, 'toggleActive'])->name('templates.toggle');
    Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');

    // Campañas masivas de WhatsApp — ver es para todos; crear/lanzar/pausar/
    // cancelar/etc. es de admin (afecta el quality rating del número).
    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::middleware('role:admin')->group(function () {
        // Rutas con segmento ESTÁTICO van antes del wildcard {campaign} para que
        // /campaigns/create no se interprete como un ID (además del whereNumber).
        Route::get('/campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
        Route::post('/campaigns/preview-audience', [CampaignController::class, 'previewAudience'])->name('campaigns.preview-audience');
        Route::post('/campaigns/preview-message', [CampaignController::class, 'previewMessage'])->name('campaigns.preview-message');
        Route::put('/campaigns/warmup', [CampaignController::class, 'updateWarmup'])->name('campaigns.warmup.update');
        Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    });
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->whereNumber('campaign')->name('campaigns.show');
    Route::get('/campaigns/{campaign}/export', [CampaignController::class, 'export'])->whereNumber('campaign')->name('campaigns.export');
    Route::middleware('role:admin')->group(function () {
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->whereNumber('campaign')->name('campaigns.update');
        Route::post('/campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->whereNumber('campaign')->name('campaigns.launch');
        Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->whereNumber('campaign')->name('campaigns.pause');
        Route::post('/campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->whereNumber('campaign')->name('campaigns.resume');
        Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->whereNumber('campaign')->name('campaigns.cancel');
        Route::post('/campaigns/{campaign}/test', [CampaignController::class, 'test'])->whereNumber('campaign')->name('campaigns.test');
        Route::post('/campaigns/{campaign}/retry-failed', [CampaignController::class, 'retryFailed'])->whereNumber('campaign')->name('campaigns.retry-failed');
        Route::post('/campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate'])->whereNumber('campaign')->name('campaigns.duplicate');
        Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])->whereNumber('campaign')->name('campaigns.destroy');
    });

    // Staff — la lista es de solo lectura para todos; gestionar staff es de admin.
    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::middleware('role:admin')->group(function () {
        Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
        Route::post('/staff/invite', [StaffController::class, 'invite'])->name('staff.invite');
        Route::put('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
        Route::patch('/staff/{staff}/toggle', [StaffController::class, 'toggle'])->name('staff.toggle');
        Route::delete('/staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');
    });

    // Teams — ver el listado/miembros es para todos; gestionar equipos es de admin.
    Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::get('/teams/{team}/members', [TeamController::class, 'members'])->name('teams.members');
    Route::middleware('role:admin')->group(function () {
        Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
        Route::post('/teams/load-samples', [TeamController::class, 'loadSamples'])->name('teams.load-samples');
        Route::put('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
    });

    // Quick Replies
    Route::get('/quick-replies', [QuickReplyController::class, 'index'])->name('quick-replies.index');
    Route::post('/quick-replies', [QuickReplyController::class, 'store'])->name('quick-replies.store');
    Route::put('/quick-replies/{quickReply}', [QuickReplyController::class, 'update'])->name('quick-replies.update');
    Route::patch('/quick-replies/{quickReply}/toggle', [QuickReplyController::class, 'toggle'])->name('quick-replies.toggle');
    Route::post('/quick-replies/{quickReply}/duplicate', [QuickReplyController::class, 'duplicate'])->name('quick-replies.duplicate');
    Route::post('/quick-replies/load-samples', [QuickReplyController::class, 'loadSamples'])->name('quick-replies.load-samples');
    Route::delete('/quick-replies/{quickReply}', [QuickReplyController::class, 'destroy'])->name('quick-replies.destroy');

    // Closing Notes — la bitácora es de solo lectura para todos; crear/editar/
    // cerrar/reabrir requiere agent o admin.
    Route::get('/closing-notes', [ClosingNoteController::class, 'index'])->name('closing-notes.index');
    Route::middleware('role:agent')->group(function () {
        Route::post('/closing-notes', [ClosingNoteController::class, 'store'])->name('closing-notes.store');
        Route::put('/closing-notes/{closingNote}', [ClosingNoteController::class, 'update'])->name('closing-notes.update');
        Route::delete('/closing-notes/{closingNote}', [ClosingNoteController::class, 'destroy'])->name('closing-notes.destroy');
        Route::post('/closing-notes/{closingNote}/reopen', [ClosingNoteController::class, 'reopen'])->name('closing-notes.reopen');
    });

    // Metrics
    Route::get('/metrics', [MetricsController::class, 'index'])->name('metrics.index');

    // Bitácora de avisos automáticos (factura generada / recordatorio de pago)
    Route::get('/notifications', [NotificationLogController::class, 'index'])->name('notifications.index');

    // Manual de Usuario
    Route::get('/manual', [ManualController::class, 'index'])->name('manual.index');

    // ── Core Brain (equipo interno) ──────────────────────────────────────────
    Route::middleware('internal')->prefix('brain')->name('brain.')->group(function () {
        Route::get('/', [Brain\CockpitController::class, 'index'])->name('cockpit');

        // Cuentas (ISPs clientes)
        Route::get('/accounts', [Brain\AccountController::class, 'index'])->name('accounts.index');
        Route::post('/accounts', [Brain\AccountController::class, 'store'])->name('accounts.store');
        Route::get('/accounts/{account}', [Brain\AccountController::class, 'show'])->name('accounts.show');
        Route::put('/accounts/{account}', [Brain\AccountController::class, 'update'])->name('accounts.update');
        Route::delete('/accounts/{account}', [Brain\AccountController::class, 'destroy'])->name('accounts.destroy');

        // Productos de la cuenta
        Route::post('/accounts/{account}/products', [Brain\AccountController::class, 'storeProduct'])->name('accounts.products.store');
        Route::put('/accounts/{account}/products/{product}', [Brain\AccountController::class, 'updateProduct'])->name('accounts.products.update');
        Route::delete('/accounts/{account}/products/{product}', [Brain\AccountController::class, 'destroyProduct'])->name('accounts.products.destroy');

        // Cobros
        Route::post('/accounts/{account}/invoices', [Brain\BillingController::class, 'storeInvoice'])->name('accounts.invoices.store');
        Route::put('/accounts/{account}/invoices/{invoice}', [Brain\BillingController::class, 'updateInvoice'])->name('accounts.invoices.update');
        Route::patch('/accounts/{account}/invoices/{invoice}/void', [Brain\BillingController::class, 'voidInvoice'])->name('accounts.invoices.void');
        Route::post('/accounts/{account}/payments', [Brain\BillingController::class, 'storePayment'])->name('accounts.payments.store');
        Route::delete('/accounts/{account}/payments/{payment}', [Brain\BillingController::class, 'destroyPayment'])->name('accounts.payments.destroy');

        // Soporte — Tickets
        Route::post('/accounts/{account}/tickets', [Brain\TicketController::class, 'storeTicket'])->name('accounts.tickets.store');
        Route::put('/accounts/{account}/tickets/{ticket}', [Brain\TicketController::class, 'updateTicket'])->name('accounts.tickets.update');
        Route::post('/accounts/{account}/tickets/{ticket}/events', [Brain\TicketController::class, 'addEvent'])->name('accounts.tickets.events.store');
        Route::delete('/accounts/{account}/tickets/{ticket}', [Brain\TicketController::class, 'destroyTicket'])->name('accounts.tickets.destroy');

        // Soporte — Notas
        Route::post('/accounts/{account}/notes', [Brain\TicketController::class, 'storeNote'])->name('accounts.notes.store');
        Route::put('/accounts/{account}/notes/{note}', [Brain\TicketController::class, 'updateNote'])->name('accounts.notes.update');
        Route::delete('/accounts/{account}/notes/{note}', [Brain\TicketController::class, 'destroyNote'])->name('accounts.notes.destroy');
        Route::patch('/accounts/{account}/notes/{note}/pin', [Brain\TicketController::class, 'pinNote'])->name('accounts.notes.pin');
    });

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/bot', [BotSettingsController::class, 'index'])->name('settings.bot.index');
    Route::put('/settings/bot', [BotSettingsController::class, 'update'])->middleware('role:admin')->name('settings.bot.update');
    Route::put('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile.update');
    Route::put('/settings/whatsapp', [SettingsController::class, 'updateWhatsApp'])->name('settings.whatsapp.update');
    Route::put('/settings/notifications', [SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');
    Route::put('/settings/assignment', [SettingsController::class, 'updateAssignment'])->middleware('role:admin')->name('settings.assignment.update');
    Route::post('/settings/whatsapp/test', [SettingsController::class, 'testWhatsAppConnection'])->name('settings.whatsapp.test');
    Route::get('/settings/whatsapp/health', [SettingsController::class, 'whatsappHealth'])->name('settings.whatsapp.health');
    Route::get('/settings/ispwatch/status', [SettingsController::class, 'ispwatchStatus'])->name('settings.ispwatch.status');
    // La vinculación con ispwatch NO es editable desde la UI: se hace con
    // `php artisan tenant:link` por el admin del SaaS.
});
