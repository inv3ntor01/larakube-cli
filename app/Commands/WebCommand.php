<?php

namespace App\Commands;

use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class WebCommand extends Command
{
    use InteractsWithClusterContext, LaraKubeOutput;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'web {--down : Remove the LaraKube Console from the cluster}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Open the LaraKube Console';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('down')) {
            return $this->call('console', ['--down' => true]);
        }

        $this->renderHeader();

        $context = $this->askForClusterContext();

        if (! $context) {
            $this->laraKubeError('No Kubernetes context selected.');

            return 1;
        }

        $this->switchClusterContext($context);

        // 🔄 Sync State with Dashboard before opening
        $this->withSpin('Synchronizing cluster state with Console...', function () {
            app(\App\Dashboard\StateSynchronizer::class)->sync();
        });

        return $this->call('console', ['--web' => true]);
    }
}
