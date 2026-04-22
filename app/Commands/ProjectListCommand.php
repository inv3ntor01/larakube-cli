<?php

namespace App\Commands;

use App\Models\Project;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ProjectListCommand extends Command
{
    use InteractsWithInternalDatabase, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'project:list';

    /**
     * The console command description.
     */
    protected $description = 'List all LaraKube projects tracked on this machine';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->ensureDatabaseIsReady();

        $projects = Project::all();

        if ($projects->isEmpty()) {
            $this->laraKubeInfo('No LaraKube projects are currently tracked.');

            return 0;
        }

        $this->laraKubeInfo('Tracked LaraKube Projects:');

        $rows = $projects->map(fn ($p) => [
            $p->name,
            $p->blueprint,
            $p->path,
            $p->updated_at->diffForHumans(),
        ])->toArray();

        $this->table(
            ['Name', 'Blueprint', 'Path', 'Last Updated'],
            $rows
        );

        return 0;
    }
}
