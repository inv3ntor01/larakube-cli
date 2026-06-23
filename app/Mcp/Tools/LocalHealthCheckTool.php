<?php

namespace App\Mcp\Tools;

use App\Data\GlobalConfigData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('local-health-check')]
#[Title('Local Health Check')]
#[Description('Verifies the status of the local orchestration environment (Docker, K3d, Networking).')]
class LocalHealthCheckTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $report = ['### 🩺 LaraKube Local Health Report'];

        // 1. Check Docker
        $dockerCheck = shell_exec('docker info > /dev/null 2>&1; echo $?');
        if (trim($dockerCheck) === '0') {
            $report[] = '- ✅ **Docker:** Engine is running.';
        } else {
            $report[] = '- ❌ **Docker:** Engine is NOT running or not accessible.';
        }

        // 2. Check K3d
        $k3dCheck = shell_exec('kubectl get nodes > /dev/null 2>&1; echo $?');
        if (trim($k3dCheck) === '0') {
            $report[] = '- ✅ **Kubernetes:** Cluster is reachable via kubectl.';
        } else {
            $report[] = "- ❌ **Kubernetes:** Cluster is NOT reachable. Try 'larakube cluster:setup'.";
        }

        // 3. Check Traefik
        $tld = GlobalConfigData::load()->getLocalTld();
        $traefikCheck = shell_exec('curl -sk --connect-timeout 2 https://console.'.$tld.' > /dev/null 2>&1; echo $?');
        if (trim($traefikCheck) === '0') {
            $report[] = "- ✅ **Networking:** Traefik ingress is routing console.{$tld}.";
        } else {
            $report[] = "- ⚠️ **Networking:** Local domains (.{$tld}) might not be resolved or Traefik is down.";
        }

        return Response::text(implode("\n", $report));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
