<?php

namespace App\Traits;

use App\Data\ConfigData;

use function Laravel\Prompts\text;

/**
 * Resolve an environment's OWN kube-context (larakube-<ip>) from its saved cloud
 * target, so a command can run `kubectl --context <env>` and NEVER switch the
 * global context. Switching context as a step is discouraged — a command should
 * target the cluster its environment points at, regardless of what the user's
 * current context happens to be.
 *
 * The connection is prompted for + persisted once (environments.{env}.cloud), so
 * every later command (deploy, plex, …) reuses it. Shared by cloud:deploy and the
 * plex commands.
 *
 * The using class must also use LaraKubeOutput + InteractsWithProjectConfig.
 */
trait ResolvesEnvironmentContext
{
    /** The kube-context cloud:provision creates for a host. Pure. */
    public function environmentContextName(string $ip): string
    {
        return 'larakube-'.$ip;
    }

    /** A `kubectl` prefix scoped to a context (or plain kubectl when null). Pure. */
    public function contextKubectl(?string $context): string
    {
        return $context !== null && $context !== ''
            ? 'kubectl --context '.escapeshellarg($context)
            : 'kubectl';
    }

    /**
     * Resolve [config, context] for an environment. If the env has no saved cloud
     * target yet, prompt for it once and persist it — so it's recorded for every
     * later command rather than relying on whatever context is currently active.
     *
     * @return array{0: ConfigData, 1: ?string}
     */
    protected function resolveEnvironmentContext(ConfigData $config, string $environment, string $projectPath): array
    {
        $cloud = $config->getCloud($environment);

        if (! $cloud || ! $cloud->ip) {
            $config = $this->captureCloudConnection($config, $environment, $projectPath);
            $cloud = $config->getCloud($environment);
        }

        return [$config, $cloud?->ip ? $this->environmentContextName($cloud->ip) : null];
    }

    /**
     * The env's context if it has a saved cloud target, else null (→ current
     * context). NON-prompting — for browse/run commands (logs, exec, shell, …)
     * that shouldn't interrupt to record a server. Local always → null, so local
     * behaviour is unchanged (plain kubectl against the current context).
     */
    protected function environmentContextOrCurrent(ConfigData $config, string $environment): ?string
    {
        if ($environment === 'local') {
            return null;
        }

        $ip = $config->getCloud($environment)?->ip;

        return $ip ? $this->environmentContextName($ip) : null;
    }

    /** `kubectl` scoped to an env's context (plain kubectl for local / no target). */
    protected function environmentKubectl(ConfigData $config, string $environment): string
    {
        return $this->contextKubectl($this->environmentContextOrCurrent($config, $environment));
    }

    /** Is the env's context present + reachable, without touching the global one? */
    protected function environmentContextReachable(?string $context): bool
    {
        exec($this->contextKubectl($context).' cluster-info --request-timeout=5s 2>&1', $out, $code);

        return $code === 0;
    }

    /**
     * Capture + persist the deploy target for an env when it isn't in the
     * blueprint yet. Saves environments.{env}.cloud and returns the reloaded config.
     */
    protected function captureCloudConnection(ConfigData $config, string $environment, string $projectPath): ConfigData
    {
        $this->laraKubeInfo("No deploy target saved for '{$environment}' yet — let's record it once.");

        $ip = text(label: 'Server IP or host', required: true);
        $user = text(label: 'SSH user', default: 'larakube', required: true);
        $port = (int) text(label: 'SSH port', default: '22', required: true);
        $key = text(label: 'SSH private key path', default: home_path('.ssh/id_rsa'), required: true);
        $key = str_replace('~', $_SERVER['HOME'] ?? getenv('HOME'), $key);

        $data = $config->toArray();
        $data['environments'][$environment]['cloud'] = [
            'ip' => trim($ip),
            'user' => trim($user),
            'port' => $port,
            'key' => $key,
        ];
        ConfigData::from($data)->saveToFile($projectPath);

        $this->laraKubeInfo("Saved to .larakube.json (environments.{$environment}.cloud) — future commands won't ask again.");

        return $this->getProjectConfig($projectPath);
    }
}
