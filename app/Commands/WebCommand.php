<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class WebCommand extends Command
{
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

        return $this->call('console', ['--web' => true]);
    }
}
