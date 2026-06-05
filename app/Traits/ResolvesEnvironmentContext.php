<?php

namespace App\Traits;

use App\Data\ConfigData;

use function Laravel\Prompts\select;
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

        // A target is "saved" if it has either a VPS ip or a managed context.
        if (! $cloud || (! $cloud->ip && ! $cloud->context)) {
            $config = $this->captureCloudConnection($config, $environment, $projectPath);
        }

        return [$config, $this->environmentContextOrCurrent($config, $environment)];
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

        $cloud = $config->getCloud($environment);

        // Managed cluster → its stored kube-context wins. VPS → derive from ip.
        if ($cloud?->context) {
            return $cloud->context;
        }

        return $cloud?->ip ? $this->environmentContextName($cloud->ip) : null;
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

        // Prefer picking an existing kube-context — that's the only way to record a
        // MANAGED cluster (DOKS/EKS/…, no IP), and it saves re-typing the IP of a
        // VPS you've already provisioned (its larakube-<ip> context is in kubeconfig).
        $contexts = $this->availableKubeContexts();
        if (! empty($contexts)) {
            $choice = select(
                label: "How is '{$environment}' reached?",
                options: array_merge(
                    array_combine($contexts, $contexts),
                    ['__new_vps__' => '➕ Enter a new server by IP (SSH)'],
                ),
            );

            if ($choice !== '__new_vps__') {
                return $this->recordContextTarget($config, $environment, $projectPath, $choice);
            }
        }

        // New VPS: capture the SSH target (needed for sideload deploy / provision).
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

    /**
     * Persist a target chosen from an existing kube-context. A `larakube-<ip>`
     * context is a VPS we provisioned — derive the ip and capture SSH so sideload
     * deploys work. Anything else is a managed cluster — store the context name
     * only (no SSH).
     */
    protected function recordContextTarget(ConfigData $config, string $environment, string $projectPath, string $context): ConfigData
    {
        $data = $config->toArray();

        if (preg_match('/^larakube-(.+)$/', $context, $m)) {
            $this->laraKubeInfo("Detected a LaraKube VPS context ({$m[1]}). Confirm its SSH details:");
            $user = text(label: 'SSH user', default: 'larakube', required: true);
            $port = (int) text(label: 'SSH port', default: '22', required: true);
            $key = text(label: 'SSH private key path', default: home_path('.ssh/id_rsa'), required: true);
            $key = str_replace('~', $_SERVER['HOME'] ?? getenv('HOME'), $key);

            $data['environments'][$environment]['cloud'] = [
                'ip' => $m[1],
                'user' => trim($user),
                'port' => $port,
                'key' => $key,
            ];
        } else {
            // Managed cluster — identified by the context name; no SSH.
            $data['environments'][$environment]['cloud'] = ['context' => $context];
        }

        ConfigData::from($data)->saveToFile($projectPath);
        $this->laraKubeInfo("Saved to .larakube.json (environments.{$environment}.cloud) — future commands won't ask again.");

        return $this->getProjectConfig($projectPath);
    }

    /**
     * Kube-context names from the local kubeconfig (managed clusters + provisioned
     * VPSes). Empty when kubectl isn't installed / has no contexts.
     *
     * @return array<int, string>
     */
    protected function availableKubeContexts(): array
    {
        exec('kubectl config get-contexts -o name 2>/dev/null', $out);

        return array_values(array_filter(array_map('trim', $out)));
    }
}
