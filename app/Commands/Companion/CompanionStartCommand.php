<?php

namespace App\Commands\Companion;

use App\Enums\CompanionDriver;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesCompanions;
use LaravelZero\Framework\Commands\Command;

class CompanionStartCommand extends Command
{
    use LaraKubeOutput, ManagesCompanions;

    protected $signature = 'companion:start {companion? : Companion slug to start (omit to pick from installed)}';

    protected $description = 'Resume a paused companion app by scaling it back up';

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
            $companion = $this->selectInstalledCompanion('start');

            if ($companion === null) {
                return 0;
            }
        }

        if (! $this->isCompanionInstalled($companion)) {
            $this->line("  <fg=gray>{$companion->getLabel()} is not installed.</>");
            $this->line('  <fg=gray>Install it with</> <fg=yellow>larakube companion:add '.$companion->value.'</>');

            return 0;
        }

        $this->withSpin("Resuming {$companion->getLabel()}...", function () use ($companion) {
            $this->scaleCompanion($companion, 1);

            return true;
        });

        $this->laraKubeInfo("▶  {$companion->getLabel()} resumed.");
        $this->line("  <fg=gray>URL:</> <fg=blue>{$companion->getUrl()}</>");

        return 0;
    }
}
