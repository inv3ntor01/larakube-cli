<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Advanced mock for the router to prevent AI/MCP providers from crashing in Laravel Zero
        $this->app->singleton('router', function () {
            return new class {
                public function get() {}
                public function post() {}
                public function middleware() { return $this; }
                public function group($options, $callback) {}
                public function aliasMiddleware() {}
                public function hasMiddleware() { return false; }
            };
        });

        // Manually register the providers
        $this->app->register(\Laravel\Ai\AiServiceProvider::class);
        $this->app->register(\Laravel\Mcp\Server\McpServiceProvider::class);
    }
}
