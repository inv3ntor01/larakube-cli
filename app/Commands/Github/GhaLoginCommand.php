<?php

namespace App\Commands\Github;

use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class GhaLoginCommand extends Command
{
    use InteractsWithGlobalConfig, LaraKubeOutput;

    protected $signature = 'gha:login';

    protected $description = 'Authenticate with GitHub using the official CLI';

    public function handle(): int
    {
        $this->renderHeader();

        $this->laraKubeInfo('Launching GitHub CLI authentication wizard...');

        $gh = $this->getGhCommand(interactive: true);
        passthru("{$gh} auth login");

        return 0;
    }
}
