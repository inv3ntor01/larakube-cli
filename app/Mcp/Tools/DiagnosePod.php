<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Traits\InteractsWithEnvironments;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class DiagnosePod extends Tool
{
    use InteractsWithEnvironments;

    public function name(): string
    {
        return 'diagnose_pod';
    }

    public function description(): string
    {
        return 'Retrieve logs and events for a specific pod to identify issues.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'pod_name' => $schema->string()
                ->description('The name of the pod to diagnose.'),
            'environment' => $schema->string()
                ->description('The environment to target. Defaults to local.')
                ->default('local'),
        ];
    }

    public function handle(string $pod_name, string $environment = 'local'): Response
    {
        $namespace = $this->getNamespace($environment);

        $logs = shell_exec("kubectl logs -n {$namespace} {$pod_name} --tail=100 2>&1");
        $events = shell_exec("kubectl get events -n {$namespace} --field-selector involvedObject.name={$pod_name} --sort-by='.lastTimestamp' 2>&1");

        $diagnosis = "--- LOGS (Last 100 lines) ---\n{$logs}\n\n--- RECENT EVENTS ---\n{$events}";

        return Response::text($diagnosis);
    }
}
