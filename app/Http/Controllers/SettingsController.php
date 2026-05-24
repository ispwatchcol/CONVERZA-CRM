<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index()
    {
        return Inertia::render('Settings/Index', [
            'settings' => [
                'whatsapp_api_url' => config('services.whatsapp.url'),
                'whatsapp_token_set' => !empty(config('services.whatsapp.token')),
                'webhook_verify_token' => config('services.whatsapp.verify_token'),
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
            ],
        ]);
    }

    public function update(Request $request)
    {
        // Settings would typically be stored in DB or .env
        // For now, this is a placeholder
        return back()->with('success', 'Configuración actualizada.');
    }
}
