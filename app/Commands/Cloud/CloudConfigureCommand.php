<?php

namespace App\Commands\Cloud;

use App\Enums\DeploymentStrategy;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Yaml\Yaml;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class CloudConfigureCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cloud:configure {action? : The configuration action (server, gha)}';

    /**
     * The console command description.
     */
    protected $description = 'Configures the server and deployment pipeline for a specific project';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $action = $this->argument('action');

        if (! $action) {
            $action = select(
                label: 'What would you like to configure?',
                options: [
                    'base' => 'Base Deployment Config (.larakube.yml)',
                    'server' => 'Initial Server Setup (Clone & Env)',
                    'gha' => 'GitHub Actions (Secrets & Workflows)',
                    'users' => 'Manage Teammate Access (SSH Keys)',
                ],
                default: 'base'
            );
        }

        return match ($action) {
            'base' => $this->configureBase(),
            'server' => $this->configureServer(),
            'gha' => $this->configureGha(),
            'users' => $this->configureUsers(),
            default => $this->laraKubeError("Unknown action: {$action}") ?? 1,
        };
    }

    protected function configureBase(): int
    {
        $environment = $this->askForEnvironment(
            label: 'Which environment are you configuring?',
            default: 'production'
        );

        $yamlFile = getcwd().'/.larakube.yml';
        $config = file_exists($yamlFile) ? Yaml::parseFile($yamlFile) : [];

        $ip = text(
            label: 'Server IP address',
            default: $config['cloud'][$environment]['ip'] ?? '',
            required: true
        );

        $user = text(
            label: 'SSH User',
            default: $config['cloud'][$environment]['user'] ?? 'larakube',
            required: true
        );

        $port = text(
            label: 'SSH Port',
            default: $config['cloud'][$environment]['port'] ?? '22'
        );

        $key = text(
            label: 'Path to SSH Private Key',
            default: $config['cloud'][$environment]['key'] ?? home_path('.ssh/id_rsa')
        );

        $config['cloud'][$environment] = [
            'ip' => $ip,
            'user' => $user,
            'port' => (int) $port,
            'key' => $key,
        ];

        file_put_contents($yamlFile, Yaml::dump($config, 4, 2));
        $this->laraKubeInfo('✅ Deployment configuration saved to .larakube.yml');

        return 0;
    }

    protected function configureServer(): int
    {
        $environment = $this->askForEnvironment(
            label: 'Which environment are you configuring for server setup?',
            default: 'production'
        );

        $yamlFile = getcwd().'/.larakube.yml';
        if (! file_exists($yamlFile)) {
            $this->laraKubeError('.larakube.yml not found. Please run cloud:configure first.');

            return 1;
        }

        $config = Yaml::parseFile($yamlFile);
        $cloud = $config['cloud'][$environment] ?? null;

        if (! $cloud) {
            $this->laraKubeError("Configuration for environment '{$environment}' not found in .larakube.yml");

            return 1;
        }

        $defaultRepo = shell_exec('git remote get-url origin 2>/dev/null') ?: '';
        $repo = text(
            label: 'Git Repository URL (SSH preferred)',
            placeholder: 'git@github.com:user/repo.git',
            default: trim($defaultRepo),
            required: true
        );

        $this->laraKubeInfo("Connecting to {$cloud['user']}@{$cloud['ip']} for initial setup...");

        // 1. SSH and Setup Directory Structure
        $projectName = basename(getcwd());
        $deployPath = "/home/{$cloud['user']}/{$projectName}";

        $this->laraKubeInfo("Cloning repository to {$deployPath}...");

        $setupCommand = <<<BASH
mkdir -p {$deployPath}
if [ ! -d "{$deployPath}/.git" ]; then
    git clone {$repo} {$deployPath}
else
    cd {$deployPath} && git pull
fi
BASH;

        $this->runRemoteCommand($cloud, $setupCommand);

        // 2. Upload .env.{env}
        $localEnv = ".env.{$environment}";
        if (file_exists($localEnv)) {
            $this->laraKubeInfo("Uploading {$localEnv} to server...");
            passthru("scp -i {$cloud['key']} -P {$cloud['port']} {$localEnv} {$cloud['user']}@{$cloud['ip']}:{$deployPath}/.env");
        } else {
            $this->laraKubeWarn("Local {$localEnv} not found. You will need to create .env on the server manually.");
        }

        $this->laraKubeInfo('✅ Server setup complete.');

        return 0;
    }

    protected function configureGha(): int
    {
        $environment = $this->askForEnvironment(
            label: 'Which environment are you configuring for GitHub Actions?',
            default: 'production'
        );

        $envFile = ".env.{$environment}";

        $this->laraKubeWarn("🛡 SECURITY CHECK: GitHub Actions will use your local '{$envFile}' as the source of truth.");
        $this->line('  Please ensure you have set your production-ready values (APP_KEY, APP_URL, etc.) in this file.');
        $this->newLine();

        if (! confirm("Are you ready to upload '{$envFile}' and your Kubeconfig to GitHub?", true)) {
            $this->laraKubeInfo('Action cancelled. Please update your environment file and try again.');

            return 0;
        }

        // 1. Configure Secrets
        $this->laraKubeInfo("Step 1: Configuring GitHub Secrets for '{$environment}' environment...");
        $this->call('gha:configure', ['environment' => $environment]);

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
            default: $environment === 'production' ? 'main' : 'develop',
            required: true
        );

        $appName = $config->getName();
        $namespace = $appName.'-'.$environment;
        $podName = $config->getServerVariation()->getPodName($config);
        $upperEnv = strtoupper($environment);

        $workflowPath = getcwd()."/.github/workflows/larakube-deploy-{$environment}.yml";
        if (! is_dir(dirname($workflowPath))) {
            mkdir(dirname($workflowPath), 0755, true);
        }

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
                'registry' => '${{ env.REGISTRY }}',
                'image_name' => '${{ env.IMAGE_NAME }}',
                'k_data' => '${{ env.K_DATA }}',
                'e_data' => '${{ env.E_DATA }}',
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
        $yamlFile = getcwd().'/.larakube.yml';
        $cloudConfig = Yaml::parseFile($yamlFile);
        $cloud = $cloudConfig['cloud'][$environment] ?? null;

        if (! $cloud) {
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
                required: true
            );
        } else {
            $this->info("  👤 Using detected GitHub user: {$username}");
        }

        if (! $token) {
            $token = password(
                label: 'GitHub Personal Access Token (PAT) with read:packages scope',
                required: true
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

    protected function configureUsers(): int
    {
        $environment = $this->askForEnvironment(
            label: 'Which environment are you syncing users to?',
            default: 'production'
        );

        $yamlFile = getcwd().'/.larakube.yml';
        if (! file_exists($yamlFile)) {
            $this->laraKubeError('.larakube.yml not found. Please run cloud:configure first.');

            return 1;
        }

        $config = Yaml::parseFile($yamlFile);
        $cloud = $config['cloud'][$environment] ?? null;

        if (! $cloud) {
            $this->laraKubeError("Configuration for environment '{$environment}' not found in .larakube.yml");

            return 1;
        }

        if (! isset($config['users'])) {
            $config['users'] = [];
        }

        $this->laraKubeInfo('LaraKube Teammate Access Manager');

        if (confirm('Would you like to add a new teammate?', true)) {
            $username = text('Username', required: true);
            $name = text('Full Name', required: true);
            $key = text('Public SSH Key', required: true);

            $config['users'][] = [
                'username' => $username,
                'name' => $name,
                'state' => 'present',
                'groups' => ['sudo'],
                'shell' => '/bin/bash',
                'authorized_keys' => [
                    ['public_key' => $key],
                ],
            ];

            file_put_contents($yamlFile, Yaml::dump($config, 4, 2));
            $this->laraKubeInfo("✅ Teammate '{$username}' added to .larakube.yml");
        }

        if (empty($config['users'])) {
            $this->laraKubeWarn('No users defined in .larakube.yml. Skipping sync.');

            return 0;
        }

        if (confirm('Sync all users to the remote server now?', true)) {
            $this->laraKubeInfo("Syncing users to {$cloud['ip']}...");

            foreach ($config['users'] as $user) {
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

            $this->laraKubeInfo('✅ All users synchronized.');
        }

        return 0;
    }

    protected function runRemoteCommand(array $cloud, string $remoteCommand): void
    {
        $sshCommand = "ssh -i {$cloud['key']} -p {$cloud['port']} {$cloud['user']}@{$cloud['ip']} ".escapeshellarg($remoteCommand);
        passthru($sshCommand);
    }
}
