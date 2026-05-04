<?php

namespace App\Commands\Project;

use App\Models\Project;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;
use Spatie\Activitylog\Models\Activity;

use function Laravel\Prompts\table;

class ProjectActivityCommand extends Command
{
    use InteractsWithInternalDatabase, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'project:activity {name? : The name of the project to view}';

    /**
     * The console command description.
     */
    protected $description = 'View the audit trail and activity log for a project';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->ensureDatabaseIsReady();

        $projectName = $this->argument('name') ?? basename(getcwd());
        $project = Project::query()->where('name', $projectName)->first();

        if (! $project) {
            $this->laraKubeError("Project '{$projectName}' is not being tracked.");

            return 1;
        }

        $activities = $project->activities()->latest()->limit(20)->get();

        if ($activities->isEmpty()) {
            $this->laraKubeInfo("No activity recorded yet for project '{$projectName}'.");

            return 0;
        }

        $this->laraKubeInfo("Audit Trail for: $projectName");

        $rows = $activities->map(fn (Activity $a) => [
            $a->description,
            json_encode($a->properties, JSON_UNESCAPED_SLASHES),
            $a->created_at->diffForHumans(),
        ])->toArray();

        table(
            ['Event', 'Details', 'Time'],
            $rows
        );

        return 0;
    }
}
