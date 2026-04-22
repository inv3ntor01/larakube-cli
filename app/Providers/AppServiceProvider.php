<?php

namespace App\Providers;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Phar;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Hide/Remove commands from binary for clean DX
        if (Phar::running() !== '') {
            Artisan::starting(function ($artisan) {
                $toHide = [
                    // AI Boilerplate
                    'make:agent',
                    'make:agent-middleware',
                    'make:tool',

                    // MCP Boilerplate
                    'make:mcp-prompt',
                    'make:mcp-resource',
                    'make:mcp-server',
                    'make:mcp-tool',
                    'mcp:inspector',
                    'mcp:start',

                    // Laravel/Database Generators
                    'make:command',
                    'make:factory',
                    'make:migration',
                    'make:model',
                    'make:seeder',
                    'make:test',

                    // Other database commands
                    'db:seed',
                ];

                foreach ($toHide as $name) {
                    $artisan->add(new class($name) extends SymfonyCommand
                    {
                        public function __construct(string $name)
                        {
                            parent::__construct($name);
                            $this->setHidden(true);
                        }

                        protected function execute(InputInterface $input, OutputInterface $output): int
                        {
                            $output->writeln('This command is disabled in the binary.');

                            return 0;
                        }
                    });
                }
            });
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Advanced mock for the router to prevent AI/MCP providers from crashing in Laravel Zero
        $this->app->singleton('router', function () {
            return new class
            {
                public function get() {}

                public function post() {}

                public function middleware()
                {
                    return $this;
                }

                public function group($options, $callback) {}

                public function aliasMiddleware() {}

                public function hasMiddleware()
                {
                    return false;
                }
            };
        });

        // Manually register the providers
        $this->app->register(AiServiceProvider::class);
        $this->app->register(McpServiceProvider::class);
        $this->app->register(ActivitylogServiceProvider::class);
    }
}
