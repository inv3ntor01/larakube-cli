<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Models\Project;
use App\Models\User;
use Exception;

trait InteractsWithInternalDatabase
{
    use InteractsWithGlobalConfig, InteractsWithProjectConfig;

    /**
     * Register or update a project in the internal database.
     */
    protected function registerProject(string $path, ConfigData $config): void
    {
        try {
            $this->ensureDatabaseIsReady();

            $email = $this->getEmail() ?? $this->getDefaultEmail();
            $user = User::query()->firstOrCreate(['email' => $email], ['name' => 'Artisan']);

            // 1. Try to find by UUID first (Persistent Identity)
            $project = null;
            if ($config->getId()) {
                $project = Project::query()->where('uuid', $config->getId())->first();
            }

            // 2. Fallback to path if no UUID or not found
            if (! $project) {
                $project = Project::query()->where('path', $path)->first();
            }

            if ($project) {
                $project->update([
                    'uuid' => $config->getId() ?? $project->uuid,
                    'path' => $path, // Update path if moved
                    'name' => basename($path),
                    'blueprint' => $config->getBlueprint() ?? $project->blueprint,
                    'config' => $config,
                ]);
            } else {
                Project::query()->create([
                    'uuid' => $config->getId() ?? null,
                    'user_id' => $user->id,
                    'name' => basename($path),
                    'path' => $path,
                    'blueprint' => $config->getBlueprint() ?? Blueprint::LARAVEL,
                    'config' => $config,
                ]);
            }
        } catch (Exception $e) {
            // Silence DB errors in CLI to prevent breaking the flow
        }
    }

    /**
     * Unregister a project from the internal database.
     */
    protected function unregisterProject(string $path): void
    {
        try {
            $this->ensureDatabaseIsReady();
            Project::query()->where('path', $path)->delete();
        } catch (Exception) {
        }
    }

    /**
     * Log an event to the activity log.
     */
    protected function logActivity(string $description, array $properties = [], ?string $path = null): void
    {
        try {
            $this->ensureDatabaseIsReady();
            $path = $path ?? getcwd();
            $email = $this->getEmail() ?? $this->getDefaultEmail();
            $user = User::query()->firstOrCreate(['email' => $email], ['name' => 'Artisan']);

            // 1. Try to find the project by ID first (Most robust)
            $project = null;
            $config = $this->getProjectConfig($path);

            if ($config->getId()) {
                $project = Project::query()->where('uuid', $config->getId())->first();
            }

            // 2. Fallback to path
            if (! $project) {
                $project = Project::query()->where('path', $path)->first();
            }

            activity()
                ->performedOn($project)
                ->causedBy($user)
                ->withProperties($properties)
                ->log($description);
        } catch (Exception) {
        }
    }

    /**
     * Ensure the internal SQLite database is fully migrated and ready.
     */
    protected function ensureDatabaseIsReady(): void
    {
        $dbPath = config('database.connections.sqlite.database');

        if (! file_exists($dbPath)) {
            @mkdir(dirname($dbPath), 0755, true);
            touch($dbPath);
        }

        $bin = $this->getLaraKubeBinaryForDb();
        system("{$bin} migrate --force > /dev/null 2>&1");
    }

    /**
     * Get the resolved LaraKube binary path specifically for DB operations.
     */
    protected function getLaraKubeBinaryForDb(): string
    {
        if (file_exists('/larakube/larakube')) {
            return 'php /larakube/larakube';
        }

        $self = $_SERVER['argv'][0] ?? 'larakube';
        if (file_exists($self)) {
            return realpath($self);
        }

        return $self;
    }
}
