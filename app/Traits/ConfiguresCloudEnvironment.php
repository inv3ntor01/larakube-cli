<?php

namespace App\Traits;

use App\Contracts\PlexProvisionable;
use App\Data\RegistryData;
use App\Enums\DeploymentStrategy;
use App\Enums\RegistryProvider;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * The individual cloud-setup steps, shared by the guided `cloud:configure` and
 * the discoverable `cloud:configure:*` commands. Keeping them here means one
 * implementation backs every entry point. Each step takes an optional
 * environment (falls back to a picker) and returns a command exit code.
 *
 * Composing commands must also use InteractsWithEnvironments,
 * InteractsWithProjectConfig and LaraKubeOutput (which provides getGhCommand()).
 */
trait ConfiguresCloudEnvironment
{
    /**
     * The full guided setup: pick the environment once, then run every step in
     * the right order — server + web host (base), an optional Commons join, and
     * CI + secrets (gha) — so there's nothing to sequence or memorise. Stops at
     * the first failing step.
     */
    protected function configureAll(): int
    {
        $environment = $this->askForCloudEnvironment(
            label: 'Which cloud environment are you setting up?',
        );

        // 1. Server connection details + the real web domain.
        if (($code = $this->configureBase($environment)) !== 0) {
            return $code;
        }

        // 2. Offer a shared Commons (runs BEFORE gha, so the rewritten .env ships).
        $this->maybeJoinCommons($environment);

        // 3. CI workflow + GitHub/GHCR secrets.
        return $this->configureGha($environment);
    }

    protected function configureBase(?string $environment = null): int
    {
        $environment ??= $this->askForCloudEnvironment(
            label: 'Which environment are you configuring?',
        );

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);
        $cloud = $config->getCloudConfig($environment);

        $this->laraKubeInfo("Configuring cloud infrastructure for '{$environment}'...");

        $ip = text(
            label: 'Server IP address',
            default: $cloud['ip'] ?? '',
            required: true,
        );

        $user = text(
            label: 'SSH User',
            default: $cloud['user'] ?? 'larakube',
            required: true,
        );

        $port = text(
            label: 'SSH Port',
            default: $cloud['port'] ?? '22',
        );

        $key = text(
            label: 'Path to SSH Private Key',
            default: $cloud['key'] ?? $_SERVER['HOME'].'/.ssh/id_rsa',
        );

        $config->setCloud($environment, [
            'ip' => $ip,
            'user' => $user,
            'port' => (int) $port,
            'key' => $key,
        ]);

        // 🌐 Ensure Web Domain is set for this env (fires for any non-local env).
        // Re-prompt when the host is missing, the {name}.com placeholder, OR a
        // local .dev.test host — which must never ship to a remote environment.
        $currentHost = $config->getHost($environment, 'web');
        if (! $currentHost || $currentHost === "{$config->getName()}.com" || str_contains((string) $currentHost, '.dev.test')) {
            $this->newLine();
            $this->info(' 🌐 ARCHITECTURAL ALIGNMENT');
            $this->line("   Remote deployments require a real web domain for '{$environment}'.");

            $host = text(
                label: "What is the REAL web domain/subdomain for '{$environment}'?",
                placeholder: $environment === 'production'
                    ? "{$config->getName()}.com"
                    : "{$environment}.{$config->getName()}.com",
                default: $currentHost ?: '',
                required: true,
            );

            $config->setHost($environment, 'web', $host);
        }

        $this->saveProjectConfig($projectPath, $config);
        $this->laraKubeInfo('✅ Cloud configuration saved to .larakube.json');

        return 0;
    }

    /**
     * Configure the container registry for an environment (GHCR / Docker Hub).
     * Drives both `cloud:deploy`'s registry push and the GitHub Actions workflow.
     */
    protected function configureRegistry(?string $environment = null): int
    {
        $environment ??= $this->askForCloudEnvironment(
            label: 'Which environment do you want to configure a registry for?',
        );

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);

        if (! isset($config->environments[$environment])) {
            $this->laraKubeError("Environment '{$environment}' is not in your blueprint.");

            return 1;
        }

        $provider = select(
            label: "Which container registry for {$environment}?",
            options: [
                RegistryProvider::GHCR->value => RegistryProvider::GHCR->label(),
                RegistryProvider::DOCKERHUB->value => RegistryProvider::DOCKERHUB->label(),
            ],
        );

        $image = text(
            label: 'Image repository path (optional, e.g. owner/repo)',
            placeholder: "Leave blank for default: {$config->getName()}",
            required: false,
        );

        $registry = new RegistryData(
            provider: RegistryProvider::from($provider),
            image: $image !== '' ? $image : null,
        );

        $config->environments[$environment]->registry = $registry;
        $this->saveProjectConfig($projectPath, $config);

        $imageLabel = $registry->image ?? $config->getName();
        $this->laraKubeInfo("✅ Registry configured for {$environment}!");
        $this->info("Provider: {$registry->provider->label()}");
        $this->info("Registry host: {$registry->getRegistryHost()}");
        $this->info("Image: {$imageLabel}");

        return 0;
    }

    /**
     * Prompt to join a shared Commons when the project's drivers map to a
     * plex-ready Commons service (a Postgres/MySQL/MariaDB database, Redis,
     * Meilisearch, or S3 via SeaweedFS/MinIO). plex:join rewrites .env and
     * regenerates manifests, so it must run before the CI secret upload reads
     * the .env.
     */
    protected function maybeJoinCommons(string $environment): void
    {
        $config = $this->getProjectConfigObject(getcwd());

        $shareable = [];
        foreach (array_filter([
            $config->getDatabase(),
            $config->getCacheDriver(),
            $config->getScoutDriver(),
            $config->getObjectStorage(),
        ]) as $driver) {
            if ($driver instanceof PlexProvisionable && $driver->isPlexReady() && $driver->commonsServiceName() !== null) {
                $shareable[] = $driver->commonsServiceName();
            }
        }

        if (empty($shareable)) {
            return;
        }

        $this->newLine();
        $this->line('  This app can share these on a Commons: <fg=cyan>'.implode(', ', array_unique($shareable)).'</>');
        if (confirm('Join a shared Commons for these (instead of self-hosting them)?', false)) {
            $this->call('plex:join', ['environment' => $environment]);
        }
    }

    protected function configureGha(?string $environment = null, bool $rotate = false): int
    {
        $environment ??= $this->askForCloudEnvironment(
            label: 'Which environment are you configuring for GitHub Actions?',
        );

        $envFile = ".env.{$environment}";

        $this->laraKubeWarn("🛡 SECURITY CHECK: GitHub Actions will use your local '{$envFile}' as the source of truth.");
        $this->line('  Please ensure you have set your production-ready values (APP_KEY, APP_URL, etc.) in this file.');
        $this->newLine();

        if (! confirm("Are you ready to upload '{$envFile}' and your Kubeconfig to GitHub?", true)) {
            $this->laraKubeInfo('Action cancelled. Please update your environment file and try again.');

            return 0;
        }

        // 1. Configure Secrets (scoped kubeconfig + env). Abort if this fails —
        //    there's no point generating a workflow with no/broken credentials.
        $this->laraKubeInfo("Step 1: Configuring GitHub Secrets for '{$environment}' environment...");
        $ghaArgs = ['environment' => $environment];
        if ($rotate) {
            $ghaArgs['--rotate'] = true;
        }
        if ($this->call('gha:configure', $ghaArgs) !== 0) {
            $this->laraKubeError('GitHub secret configuration failed — aborting before generating the workflow.');

            return 1;
        }

        // 2. Setup GHCR Pull Secret on the VPS (if Single-Node)
        $config = $this->getProjectConfigObject(getcwd());
        if ($config->getStrategy() === DeploymentStrategy::SINGLE_NODE) {
            $this->laraKubeInfo('Step 2: Securing VPS access to Private Registry (GHCR)...');
            $this->setupGhcrSecret($environment);
        }

        // 3. Generate Workflow
        $this->laraKubeInfo('Step 3: Generating Cloud Pilot workflow...');

        $branch = text(
            label: "Which git branch should trigger the {$environment} deployment?",
            default: 'main',
            required: true,
        );

        $appName = $config->getName();
        $namespace = $appName.'-'.$environment;
        $podName = $config->getServerVariation()->getPodName($config);
        $upperEnv = strtoupper($environment);

        $workflowPath = getcwd()."/.github/workflows/larakube-deploy-{$environment}.yml";
        if (! is_dir(dirname($workflowPath))) {
            mkdir(dirname($workflowPath), 0755, true);
        }

        // Determine registry configuration
        $registry = $config->getRegistry($environment);
        $registryProvider = $registry ? $registry->provider->value : 'ghcr';
        $registryHost = $registry ? $registry->getRegistryHost() : 'ghcr.io';
        $imageName = $registry ? ($registry->image ?? '${{ github.repository }}') : '${{ github.repository }}';

        $workflowContent = view('k8s.cloud-pilot-deploy', [
            'config' => $config,
            'environment' => $environment,
            'branch' => $branch,
            'appName' => $appName,
            'namespace' => $namespace,
            'podName' => $podName,
            'upperEnv' => $upperEnv,
            'secrets' => [
                'k_env' => '${{ secrets.'.$upperEnv.'_KUBECONFIG }}',
                'k_base' => '${{ secrets.KUBECONFIG }}',
                'e_env' => '${{ secrets.'.$upperEnv.'_ENV_FILE_BASE64 }}',
                'e_base' => '${{ secrets.ENV_FILE_BASE64 }}',
            ],
            'gha' => [
                'repository' => '${{ github.repository }}',
                'actor' => '${{ github.actor }}',
                'token' => '${{ secrets.GITHUB_TOKEN }}',
                'sha' => '${{ github.sha }}',
                'registry_provider' => $registryProvider,
                'registry_host' => $registryHost,
                'image_name' => $imageName,
                'k_data' => '${{ env.K_DATA }}',
                'e_data' => '${{ env.E_DATA }}',
                // GitHub expressions must be passed as values (not hardcoded as
                // literal text in the Blade), or Blade mangles the inner {{ }}.
                'image_latest' => '${{ env.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:latest',
                'image_sha' => '${{ env.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:${{ github.sha }}',
                'dockerhub_user' => '${{ secrets.DOCKERHUB_USERNAME }}',
                'dockerhub_token' => '${{ secrets.DOCKERHUB_TOKEN }}',
            ],
        ])->render();

        file_put_contents($workflowPath, $workflowContent);

        $this->laraKubeInfo("✅ GitHub Actions configured successfully for '{$environment}'!");
        $this->info("Workflow saved to: .github/workflows/larakube-deploy-{$environment}.yml");
        $this->line("Push to '{$branch}' to trigger your Cloud Pilot deployment.");

        return 0;
    }

    protected function setupGhcrSecret(string $environment): void
    {
        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);
        $cloud = $config->getCloudConfig($environment);

        if (empty($cloud)) {
            $this->laraKubeWarn("Cloud config not found for {$environment}. Skipping registry secret setup.");

            return;
        }

        $gh = $this->getGhCommand();

        // 1. Attempt to auto-detect username
        $username = trim(shell_exec("{$gh} api user -q .login 2>/dev/null") ?? '');

        // 2. Attempt to auto-detect token
        $token = trim(shell_exec("{$gh} auth token 2>/dev/null") ?? '');

        if (! $username) {
            $username = text(
                label: 'GitHub Username',
                required: true,
            );
        } else {
            $this->info("  👤 Using detected GitHub user: {$username}");
        }

        if (! $token) {
            $token = password(
                label: 'GitHub Personal Access Token (PAT) with read:packages scope',
                required: true,
            );
        } else {
            $this->info('  🔑 Using existing GitHub authentication token.');
        }

        $config = $this->getProjectConfigObject(getcwd());
        $namespace = $config->getName().'-'.$environment;

        $remoteCommand = <<<BASH
kubectl create namespace {$namespace} --dry-run=client -o yaml | kubectl apply -f -
kubectl create secret docker-registry ghcr-login \
  --namespace={$namespace} \
  --docker-server=https://ghcr.io \
  --docker-username={$username} \
  --docker-password={$token} \
  --dry-run=client -o yaml | kubectl apply -f -
BASH;

        $this->runRemoteCommand($cloud, $remoteCommand);
        $this->laraKubeInfo("✅ GHCR pull secret created on VPS in namespace: {$namespace}");
    }

    protected function configureUsers(?string $environment = null): int
    {
        $environment ??= $this->askForCloudEnvironment(
            label: 'Which environment are you syncing users to?',
        );

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);
        $cloud = $config->getCloudConfig($environment);

        if (empty($cloud)) {
            $this->laraKubeError("Configuration for environment '{$environment}' not found. Please run cloud:configure first.");

            return 1;
        }

        $this->laraKubeInfo('LaraKube Teammate Access Manager');

        if (confirm('Would you like to add a new teammate?', true)) {
            $username = text('Username', required: true);
            $name = text('Full Name', required: true);
            $key = text('Public SSH Key', required: true);

            $config->addTeammate($environment, [
                'username' => $username,
                'name' => $name,
                'state' => 'present',
                'groups' => ['sudo'],
                'shell' => '/bin/bash',
                'authorized_keys' => [
                    ['public_key' => $key],
                ],
            ]);

            $this->saveProjectConfig($projectPath, $config);
            $this->laraKubeInfo("✅ Teammate '{$username}' added to .larakube.json");
        }

        $teammates = $config->getTeammates($environment);

        if (empty($teammates)) {
            $this->laraKubeWarn("No teammates defined for '{$environment}'. Skipping sync.");

            return 0;
        }

        if (confirm('Sync all teammates to the remote server now?', true)) {
            $this->laraKubeInfo("Syncing teammates to {$cloud['ip']}...");

            foreach ($teammates as $user) {
                $username = $user['username'];
                if (($user['state'] ?? 'present') === 'present') {
                    $this->laraKubeInfo("  👤 Ensuring user: {$username}");

                    $keys = array_map(fn ($k) => $k['public_key'], $user['authorized_keys']);
                    $keysString = implode("\n", $keys);

                    $remoteCommand = <<<BASH
if ! id "{$username}" &>/dev/null; then
    useradd -m -s {$user['shell']} {$username}
fi
usermod -aG sudo {$username}
mkdir -p /home/{$username}/.ssh
echo "{$keysString}" > /home/{$username}/.ssh/authorized_keys
chown -R {$username}:{$username} /home/{$username}/.ssh
chmod 700 /home/{$username}/.ssh
chmod 600 /home/{$username}/.ssh/authorized_keys
echo "{$username} ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/{$username}
BASH;
                    $this->runRemoteCommand($cloud, $remoteCommand);
                }
            }

            $this->laraKubeInfo('✅ All teammates synchronized.');
        }

        return 0;
    }

    protected function runRemoteCommand(array $cloud, string $remoteCommand): int
    {
        $sshCommand = "ssh -i {$cloud['key']} -p {$cloud['port']} {$cloud['user']}@{$cloud['ip']} ".escapeshellarg($remoteCommand);
        passthru($sshCommand, $exitCode);

        return (int) $exitCode;
    }
}
