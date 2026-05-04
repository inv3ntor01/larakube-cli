<?php

namespace App\Commands;

use App\Ai\Agents\LaraKubeAssistantAgent;
use App\Enums\AiProvider;
use App\Models\User;
use App\Traits\InteractsWithDynamicOptions;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\LaraKubeOutput;
use Exception;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\text;

class ChatCommand extends Command
{
    use InteractsWithDynamicOptions, InteractsWithGlobalConfig, InteractsWithInternalDatabase, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'chat {query? : A single-shot natural language query}
                           {--query= : A single-shot natural language query}
                           {--new : Start a fresh conversation for this project}';

    /**
     * Configure the command to ignore validation errors so we can forward arbitrary flags.
     */
    protected function configure(): void
    {
        $this->ignoreValidationErrors();
        $this->addAiProviderOptions();
    }

    /**
     * The console command description.
     */
    protected $description = 'Interact with LaraKube using natural language (alias: ask)';

    /**
     * The console command aliases.
     */
    protected $aliases = ['ask'];

    public function handle(): int
    {
        $this->renderHeader();
        $this->ensureDatabaseIsReady();

        $provider = $this->getAiProvider()->value;

        // Check if a specific provider flag was passed
        foreach (AiProvider::cases() as $case) {
            if ($this->option($case->value)) {
                $provider = $case->value;
                break;
            }
        }

        $apiKey = $this->getAiApiKey($provider);

        if (! $apiKey) {
            $this->error("  ✖ AI API Key not found for provider '{$provider}'.");
            $this->info("    👉 Use: larakube config:ai --{$provider}=YOUR_KEY");

            return 1;
        }

        config(['ai.default' => $provider]);
        config(["ai.providers.{$provider}.key" => $apiKey]);

        $email = $this->getEmail() ?? $this->getDefaultEmail();
        $user = User::query()->firstOrCreate(['email' => $email], ['name' => 'Artisan']);

        // 2. Find or Create a Conversation scoped strictly to this directory (Email-Agnostic)
        $title = 'CWD: '.getcwd();
        $conversationId = null;

        if (! $this->option('new')) {
            $conversationId = DB::table('agent_conversations')
                ->where('title', $title)
                ->value('id');
        }

        if (! $conversationId) {
            $conversationId = resolve(ConversationStore::class)
                ->storeConversation(null, $title);

            $this->logActivity('Started new AI conversation', ['title' => $title]);
        }

        $query = $this->argument('query') ?: $this->option('query');

        // 2. Single-Shot Mode
        if ($query) {
            $this->performChat($conversationId, $user, $query);

            return 0;
        }

        // 3. Interactive Mode
        $this->laraKubeInfo('Welcome to LaraKube CLI Chat! How can I help you today?');
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

            $this->performChat($conversationId, $user, $query);
        }

        return 0;
    }

    protected function performChat(string $conversationId, User $user, string $query): void
    {
        $this->info('🧠 LaraKube is analyzing...');
        $this->line('');
        $this->output->write('🤖 LaraKube: ');

        try {
            $stream = LaraKubeAssistantAgent::make()->continue($conversationId, $user)->stream($query);

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
