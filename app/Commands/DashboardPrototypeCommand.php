<?php

namespace App\Commands;

use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class DashboardPrototypeCommand extends Command
{
    use InteractsWithClusterContext, LaraKubeOutput;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'dashboard:prototype {--screen=cluster-dashboard : The screen to preview (cluster-dashboard, project-details, networking)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Preview the new Stitch-generated Web UI prototypes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $screen = $this->option('screen');
        $viewPath = "dashboard.stubs.{$screen}";

        if (! view()->exists($viewPath)) {
            $this->laraKubeError("Prototype screen [{$screen}] not found.");
            return 1;
        }

        $this->laraKubeInfo("Launching prototype: {$screen}");

        // Create a temporary HTML file to open in the browser
        $html = view($viewPath)->render();
        $tmpFile = sys_get_temp_dir() . "/larakube-prototype-{$screen}.html";
        file_put_contents($tmpFile, $html);

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        $this->info("  Opening local prototype at: file://{$tmpFile}");
        passthru("{$command} \"file://{$tmpFile}\"");

        $this->newLine();
        $this->info("💡 To fully integrate this, you should:");
        $this->line("   1. Move these templates into the 'larakube-dashboard' repository.");
        $this->line("   2. Connect the dynamic elements (e.g., project names, logs) to the Dashboard API.");
        $this->line("   3. Rebuild the dashboard image and update your CLI manifest.");

        return 0;
    }
}
