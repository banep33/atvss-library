<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\NormalizeInventarski::class,
            ]);
        }

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // U produkciji svi generisani linkovi (redirekcije, asset URL-ovi,
        // form action) idu preko HTTPS-a. Uključuje se tek kad se na serveru
        // postavi FORCE_HTTPS=true — lokalni HTTP razvoj ostaje netaknut.
        if (env('FORCE_HTTPS', false)) {
            URL::forceScheme('https');
        }

        if (!app()->runningInConsole() && config('ai.warmup_enabled', true)) {
           try {
              if (!cache()->has('ollama_warmed_up')) {
                cache(['ollama_warmed_up' => true], now()->addMinutes(70));
                // LLM model (qwen2.5:3b) u RAM — cold-start mitigacija.
                app(\App\Services\Ai\OllamaClient::class)->warmup();
                // Embed model (nomic-embed-text) + langdetect profili — prvi
                // RAG upit inače plaća hladan start. Fire-and-forget.
                app(\App\Services\Ai\RagClient::class)->warmup();
              }
            } catch (\Throwable) {}
        }
    }
}
