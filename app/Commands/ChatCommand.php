<?php

namespace App\Commands;

use App\Ai\LaraKubeAssistantAgent;
use App\Models\User;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\LaraKubeOutput;
use Exception;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\text;

class ChatCommand extends Command
{
    use InteractsWithInternalDatabase, LaraKubeOutput;

    protected $signature = 'chat {--query= : A single-shot natural language query to execute}';

    protected $description = 'Interact with LaraKube using natural language';

    public function handle(): int
    {
        $this->renderHeader();
        $this->ensureDatabaseIsReady();

        $provider = $this->getAiProvider();
        $apiKey = $this->getAiApiKey($provider);

        if (! $apiKey) {
            $this->error("  ✖ AI API Key not found for provider '{$provider}'.");
            $this->info("    👉 Use: larakube config:ai --{$provider}=YOUR_KEY");

            return 1;
        }

        config(['ai.default' => $provider]);
        config(["ai.providers.{$provider}.key" => $apiKey]);

        // Initialize a SINGLE persistent agent for this session
        $agent = LaraKubeAssistantAgent::make();

        // 1. Establish User Context and Memory
        try {
            if ($user = User::where('email', $this->getEmail())->first()) {
                // Resume the last conversation for this user
                $agent = $agent->continueLastConversation($user);
            }
        } catch (Exception $e) {
            // Silence early boot errors
        }

        // 2. Single-Shot Mode
        if ($query = $this->option('query')) {
            $this->info("🧠 LaraKube Single-Shot: {$query}");
            $this->performChat($agent, $query);

            return 0;
        }

        // 3. Interactive Mode
        $this->laraKubeInfo('Welcome to LaraKube Chat! How can I help you today?');
        while (true) {
            $query = text(
                label: 'You',
                placeholder: 'e.g., Create a new project called masterpiece...',
                required: true
            );

            if (in_array(strtolower($query), ['exit', 'quit', 'bye'])) {
                $this->info('  👋 Goodbye!');
                break;
            }

            $this->performChat($agent, $query);
        }

        return 0;
    }

    protected function performChat(LaraKubeAssistantAgent $agent, string $query): void
    {
        $this->info('🧠 LaraKube is analyzing...');
        $this->line('');
        $this->output->write('🤖 LaraKube: ');

        try {
            $stream = $agent->stream($query);

            foreach ($stream as $event) {
                if ($event instanceof TextDelta) {
                    $this->output->write($event->delta);
                } elseif ($event instanceof ToolCall) {
                    $this->line("\n  <fg=gray>🛠 Calling Tool:</> <fg=yellow>{$event->toolCall->name}</>");
                    if (isset($event->toolCall->arguments['command'])) {
                        $this->line("  <fg=gray>➤ Command:</> <fg=blue>{$event->toolCall->arguments['command']}</>");
                    }
                }
            }

            $this->line("\n");
        } catch (Exception $e) {
            $this->line('');
            $this->error('  ✖ Error: '.$e->getMessage());
            $this->line('');
        }
    }
}
