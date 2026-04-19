<?php

namespace App\Commands;

use App\Ai\LaraKubeAssistantAgent;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;
use Laravel\Ai\Events\TextChunkReceived;
use Laravel\Ai\Events\ToolCallStarted;
use Laravel\Ai\Events\ToolCallFinished;

use function Laravel\Prompts\text;

class ChatCommand extends Command
{
    use InteractsWithGlobalConfig, LaraKubeOutput;

    protected $signature = 'chat';

    protected $description = 'Interact with LaraKube using natural language';

    public function handle()
    {
        $this->renderHeader();
        $this->laraKubeInfo('Welcome to LaraKube Chat! How can I help you today?');

        $provider = $this->getAiProvider();
        $apiKey = $this->getAiApiKey();

        if (! $apiKey) {
            $this->error("  ✖ AI API Key not found for provider '{$provider}'.");
            $this->info("    👉 Use: larakube config:ai --{$provider}=YOUR_KEY");
            return 1;
        }

        config(['ai.default' => $provider]);
        config(["ai.providers.{$provider}.key" => $apiKey]);

        while (true) {
            $query = text(
                label: 'You',
                placeholder: 'e.g., Create a new project called masterpiece...',
                required: true
            );

            if (in_array(strtolower($query), ['exit', 'quit', 'bye'])) {
                $this->info('Goodbye!');
                break;
            }

            $this->info('🧠 LaraKube is analyzing...');
            $this->line('');
            $this->output->write('🤖 LaraKube: ');

            try {
                LaraKubeAssistantAgent::make()
                    ->stream($query)
                    ->each(function ($event) {
                        if ($event instanceof TextChunkReceived) {
                            $this->output->write($event->text);
                        } elseif ($event instanceof ToolCallStarted) {
                            $this->line("\n  <fg=gray>🛠 Calling Tool:</> <fg=yellow>{$event->toolCall->name}</>");
                        } elseif ($event instanceof ToolCallFinished) {
                            $this->line("  <fg=green>✔ Tool execution complete.</>");
                        }
                    });
                
                $this->line("\n");
            } catch (\Exception $e) {
                $this->line('');
                $this->error('  ✖ Error: ' . $e->getMessage());
                $this->line('');
            }
        }

        return 0;
    }
}
