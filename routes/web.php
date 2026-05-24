<?php

use Illuminate\Support\Facades\Route;
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
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WhatsAppController;

// Dashboard
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Contacts
Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
Route::put('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');

// Labels
Route::get('/labels', [LabelController::class, 'index'])->name('labels.index');
Route::post('/labels', [LabelController::class, 'store'])->name('labels.store');
Route::put('/labels/{label}', [LabelController::class, 'update'])->name('labels.update');
Route::delete('/labels/{label}', [LabelController::class, 'destroy'])->name('labels.destroy');

// Chat
Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
Route::post('/chat/send', [ChatController::class, 'sendMessage'])->name('chat.send');

// Templates (Etiquetas/Plantillas Meta)
Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
Route::patch('/templates/{template}/toggle', [TemplateController::class, 'toggleActive'])->name('templates.toggle');
Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');

// Staff
Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
Route::put('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
Route::delete('/staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');

// Teams
Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
Route::put('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
Route::delete('/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');

// Quick Replies
Route::get('/quick-replies', [QuickReplyController::class, 'index'])->name('quick-replies.index');
Route::post('/quick-replies', [QuickReplyController::class, 'store'])->name('quick-replies.store');
Route::put('/quick-replies/{quickReply}', [QuickReplyController::class, 'update'])->name('quick-replies.update');
Route::delete('/quick-replies/{quickReply}', [QuickReplyController::class, 'destroy'])->name('quick-replies.destroy');

// Closing Notes
Route::get('/closing-notes', [ClosingNoteController::class, 'index'])->name('closing-notes.index');
Route::post('/closing-notes', [ClosingNoteController::class, 'store'])->name('closing-notes.store');

// Metrics
Route::get('/metrics', [MetricsController::class, 'index'])->name('metrics.index');

// Settings
Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');

// WhatsApp Webhook (public, no auth)
Route::get('/webhook', [WhatsAppController::class, 'verifyWebhook'])->name('webhook.verify');
Route::post('/webhook', [WhatsAppController::class, 'handleWebhook'])->name('webhook.handle');
