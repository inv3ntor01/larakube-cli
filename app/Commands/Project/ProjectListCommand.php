<?php

namespace App\Commands\Project;

use App\Models\Project;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\table;

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

        if ($this->isAiAgent()) {
            return $this->renderJson([
                'projects' => $projects->map(fn ($p) => [
                    'name' => $p->name,
                    'blueprint' => $p->blueprint?->value,
                    'path' => $p->path,
                    'updated_at' => $p->updated_at->toIso8601String(),
                ])->toArray(),
            ]);
        }

        if ($projects->isEmpty()) {
            $this->laraKubeInfo('No LaraKube projects are currently tracked.');

            return 0;
        }

        $this->laraKubeInfo('Tracked LaraKube Projects:');

        $rows = $projects->map(fn ($p) => [
            $p->name,
            $p->blueprint?->getLabel() ?? 'Unknown',
            $p->path,
            $p->updated_at->diffForHumans(),
        ])->toArray();

        table(
            ['Name', 'Blueprint', 'Path', 'Last Updated'],
            $rows
        );

        return 0;
    }
}
