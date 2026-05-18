<?php

namespace App\Commands;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\select;

class ContextCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'context {name? : The name of the context to switch to}';

    /**
     * The console command description.
     */
    protected $description = 'Switch between Kubernetes contexts easily';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $contextsOutput = shell_exec('kubectl config get-contexts -o name 2>/dev/null');
        $currentContext = trim(shell_exec('kubectl config current-context 2>/dev/null') ?? '');

        if (! $contextsOutput) {
            $this->laraKubeError('No Kubernetes contexts found. Please ensure kubectl is installed and configured.');

            return 1;
        }

        $contexts = array_filter(explode("\n", trim($contextsOutput)));

        if (empty($contexts)) {
            $this->laraKubeError('No Kubernetes contexts found.');

            return 1;
        }

        $targetContext = $this->argument('name');

        if (! $targetContext) {
            $targetContext = select(
                label: 'Which Kubernetes context would you like to use?',
                options: array_combine($contexts, $contexts),
                default: $currentContext ?: null
            );
        }

        if (! in_array($targetContext, $contexts)) {
            $this->laraKubeError("Context '{$targetContext}' not found.");

            return 1;
        }

        if ($targetContext === $currentContext) {
            $this->laraKubeInfo("Already on context: <fg=cyan;options=bold>{$targetContext}</>");

            return 0;
        }

        exec('kubectl config use-context '.escapeshellarg($targetContext), $output, $resultCode);

        if ($resultCode === 0) {
            $this->laraKubeInfo("Switched to context: <fg=cyan;options=bold>{$targetContext}</>");
        } else {
            $this->laraKubeError('Failed to switch context.');

            return 1;
        }

        return 0;
    }
}
