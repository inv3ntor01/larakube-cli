<?php

namespace App\Traits;

use App\Models\LaraKubeActivity;
use App\Models\Project;
use App\Models\User;

trait InteractsWithInternalDatabase
{
    use InteractsWithGlobalConfig;

    /**
     * Register or update a project in the internal database.
     */
    protected function registerProject(string $path, array $config): void
    {
        try {
            $this->ensureDatabaseIsReady();

            $email = $this->getEmail() ?? 'guest@larakube.dev.test';
            $user = User::firstOrCreate(['email' => $email], ['name' => 'Artisan']);

            // 1. Try to find by UUID first (Persistent Identity)
            $project = null;
            if (isset($config['id'])) {
                $project = Project::where('uuid', $config['id'])->first();
            }

            // 2. Fallback to path if no UUID or not found
            if (! $project) {
                $project = Project::where('path', $path)->first();
            }

            if ($project) {
                $project->update([
                    'uuid' => $config['id'] ?? $project->uuid,
                    'path' => $path, // Update path if moved
                    'name' => basename($path),
                    'blueprint' => $config['blueprint'] ?? $project->blueprint,
                    'config' => $config,
                ]);
            } else {
                Project::create([
                    'uuid' => $config['id'] ?? null,
                    'user_id' => $user->id,
                    'name' => basename($path),
                    'path' => $path,
                    'blueprint' => $config['blueprint'] ?? 'standard',
                    'config' => $config,
                ]);
            }
        } catch (\Exception $e) {
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
            Project::where('path', $path)->delete();
        } catch (\Exception $e) {
            // Silence
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
            $email = $this->getEmail() ?? 'guest@larakube.dev.test';
            $user = User::firstOrCreate(['email' => $email], ['name' => 'Artisan']);

            // 1. Try to find the project by ID first (Most robust)
            $project = null;
            $configPath = $path.'/.larakube.json';
            if (file_exists($configPath)) {
                $config = json_decode(file_get_contents($configPath), true);
                if (isset($config['id'])) {
                    $project = Project::where('uuid', $config['id'])->first();
                }
            }

            // 2. Fallback to path
            if (! $project) {
                $project = Project::where('path', $path)->first();
            }

            LaraKubeActivity::create([
                'causer_id' => $user->id,
                'causer_type' => get_class($user),
                'subject_id' => $project?->id,
                'subject_type' => $project ? get_class($project) : null,
                'description' => $description,
                'properties' => $properties,
            ]);
        } catch (\Exception $e) {
            // Silence
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
