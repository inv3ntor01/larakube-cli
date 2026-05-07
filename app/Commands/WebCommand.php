<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class WebCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'web {--down : Remove the LaraKube System Dashboard from the cluster}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Open the LaraKube System Web Dashboard';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('down')) {
            return $this->call('dashboard', ['--down' => true]);
        }

        return $this->call('dashboard', ['--web' => true]);
    }
}
