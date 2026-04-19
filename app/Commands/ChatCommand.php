<?php

namespace App\Commands;

use App\Ai\LaraKubeAssistantAgent;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

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

        $apiKey = $this->getAiApiKey();
        if (! $apiKey) {
            $this->error('  ✖ AI API Key not found. Please set it using: larakube config --ai-key=YOUR_KEY');
            return 1;
        }

        config(['ai.providers.gemini.key' => $apiKey]);

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

            $this->info('LaraKube is thinking...');

            try {
                $response = LaraKubeAssistantAgent::make()->prompt($query);
                $this->line('');
                $this->line("🤖 LaraKube: " . $response->text());
                $this->line('');
            } catch (\Exception $e) {
                $this->error('  ✖ Error: ' . $e->getMessage());
            }
        }

        return 0;
    }
}
