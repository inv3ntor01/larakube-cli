<?php

namespace App\Commands\Companion;

use App\Enums\CompanionDriver;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesCompanions;
use LaravelZero\Framework\Commands\Command;

class CompanionStopCommand extends Command
{
    use LaraKubeOutput, ManagesCompanions;

    protected $signature = 'companion:stop {companion? : Companion slug to stop (omit to pick from installed)}';

    protected $description = 'Pause a companion app by scaling it to zero (its config is preserved)';

    public function handle(): int
    {
        $this->renderHeader();

        $slug = $this->argument('companion');

        if ($slug !== null) {
            $companion = CompanionDriver::tryFrom($slug);

            if ($companion === null) {
                $this->error("  Unknown companion: {$slug}");
                $this->line('  Available: '.implode(', ', array_map(fn ($c) => $c->value, CompanionDriver::cases())));

                return 1;
            }
        } else {
            $companion = $this->selectInstalledCompanion('stop');

            if ($companion === null) {
                return 0;
            }
        }

        if (! $this->isCompanionInstalled($companion)) {
            $this->line("  <fg=gray>{$companion->getLabel()} is not installed.</>");

            return 0;
        }

        $this->withSpin("Pausing {$companion->getLabel()}...", function () use ($companion) {
            $this->scaleCompanion($companion, 0);

            return true;
        });

        $this->laraKubeInfo("⏸  {$companion->getLabel()} paused — its data and config remain in the cluster.");
        $this->line('  <fg=gray>Resume with</> <fg=yellow>larakube companion:start '.$companion->value.'</>');

        return 0;
    }
}
