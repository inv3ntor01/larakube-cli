<?php

namespace App\Commands\Config;

use App\Enums\AiProvider;
use App\Traits\InteractsWithDynamicOptions;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Input\InputOption;

class ConfigAiCommand extends Command
{
    use InteractsWithDynamicOptions, InteractsWithGlobalConfig, LaraKubeOutput;

    protected $signature = 'config:ai {--provider= : Explicitly set the preferred AI provider}';

    protected $description = 'Configure AI settings and API keys';

    protected function configure(): void
    {
        $this->ignoreValidationErrors();
        $this->addAiProviderOptions(InputOption::VALUE_REQUIRED);
    }

    public function handle()
    {
        $this->renderHeader();

        $updated = false;
        $providedKeys = [];

        foreach (AiProvider::cases() as $case) {
            if ($key = $this->option($case->value)) {
                $this->setAiApiKey($key, $case->value);
                $this->info("  ✔ API key updated for: {$case->getLabel()}");
                $providedKeys[] = $case->value;
                $updated = true;
            }
        }

        $explicitProvider = $this->option('provider');

        if (count($providedKeys) === 1 && ! $explicitProvider) {
            $inferredProvider = $providedKeys[0];
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
