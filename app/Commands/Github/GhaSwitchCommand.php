<?php

namespace App\Commands\Github;

use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class GhaSwitchCommand extends Command
{
    use InteractsWithGlobalConfig, LaraKubeOutput;

    protected $signature = 'gha:switch';

    protected $description = 'Switch between GitHub accounts';

    public function handle()
    {
        $this->renderHeader();

        $this->laraKubeInfo('Switching GitHub accounts...');

        $gh = $this->getGhDockerCommand();
        passthru("{$gh} auth switch");

        return 0;
    }
}
