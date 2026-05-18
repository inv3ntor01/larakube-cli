<?php

namespace App\Commands\Github;

use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class GhaUserCommand extends Command
{
    use InteractsWithGlobalConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gha:user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the currently authenticated GitHub user';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $this->laraKubeInfo('Checking GitHub authentication status...');

        $gh = $this->getGhCommand();

        $username = trim(shell_exec("{$gh} api user -q .login 2>/dev/null") ?? '');

        if (! $username) {
            $this->laraKubeError('Not authenticated with GitHub.');
            $this->line('  👉 Run <fg=yellow;options=bold>larakube gha:login</> to sign in.');

            return 1;
        }

        $this->laraKubeInfo("Authenticated as: <fg=cyan;options=bold>{$username}</>");

        return 0;
    }
}
