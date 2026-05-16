<?php

namespace App\Traits;

use App\Dashboard\DashboardConnector;
use App\Dashboard\Requests\SendActivityLogRequest;
use Illuminate\Support\Facades\Http;

trait HasConsoleInteraction
{
    protected function logToConsole(string $projectUuid, string $event, string $description, array $properties = []): void
    {
        $connector = new DashboardConnector;

        $connector->send(new SendActivityLogRequest(
            $projectUuid,
            $event,
            $description,
            $properties
        ));
    }

    protected function registerWithConsole(array $projectData): bool
    {
        $connector = new DashboardConnector;

        try {
            return Http::timeout(5)
                ->withoutVerifying()
                ->post('https://console.dev.test/api/projects/register', $projectData)
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function logActivity(string $description, array $properties = [], ?string $path = null): void
    {
        $path = $path ?? getcwd();
        $config = method_exists($this, 'getProjectConfig') ? $this->getProjectConfig($path) : null;

        if ($config && $config->getId()) {
            $this->logToConsole(
                $config->getId(),
                $properties['action'] ?? 'activity',
                $description,
                $properties
            );
        }
    }

    protected function isConsoleRunning(): bool
    {
        return (new DashboardConnector)->isUp();
    }
}
