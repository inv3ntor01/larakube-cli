<?php

namespace App\Commands;

use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ConfigAiCommand extends Command
{
    use InteractsWithGlobalConfig, LaraKubeOutput;

    protected $signature = 'config:ai {--gemini= : Set the Gemini API Key} 
                                      {--openai= : Set the OpenAI API Key} 
                                      {--anthropic= : Set the Anthropic API Key}
                                      {--provider= : Explicitly set the preferred AI provider}';

    protected $description = 'Configure AI settings and API keys';

    public function handle()
    {
        $this->renderHeader();

        $providedKeys = array_filter([
            'gemini' => $this->option('gemini'),
            'openai' => $this->option('openai'),
            'anthropic' => $this->option('anthropic'),
        ]);

        $explicitProvider = $this->option('provider');
        $updated = false;

        foreach ($providedKeys as $name => $key) {
            $this->setAiApiKey($key, $name);
            $this->info("  ✔ API key updated for: {$name}");
            $updated = true;
        }

        if (count($providedKeys) === 1 && ! $explicitProvider) {
            $inferredProvider = array_key_first($providedKeys);
            $this->setAiProvider($inferredProvider);
            $this->info("  ✔ Default AI provider set to: {$inferredProvider}");
            $updated = true;
        }

        if ($explicitProvider) {
            $explicitProvider = strtolower($explicitProvider);
            $this->setAiProvider($explicitProvider);
            $this->info("  ✔ Default AI provider set to: {$explicitProvider}");
            $updated = true;
        }

        if (! $updated) {
            $this->call('config');
        }

        return 0;
    }
}
