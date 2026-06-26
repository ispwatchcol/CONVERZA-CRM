<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Observers\ConversationObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Conversation::observe(ConversationObserver::class);
    }
}
