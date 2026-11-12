<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Observers\ConversationObserver;
use App\Services\Ai\AiProvider;
use App\Services\Ai\OpenAiProvider;
use App\Services\Ai\XaiProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // El proveedor lo elige AI_PROVIDER; el match es donde se agrega otro.
        $this->app->bind(AiProvider::class, fn () => match (config('ai.provider')) {
            'openai' => new OpenAiProvider(),
            default  => new XaiProvider(),
        });
    }

    public function boot(): void
    {
        Conversation::observe(ConversationObserver::class);
    }
}
