<?php

namespace App\Commands\Github;

use App\Data\ConfigData;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithScopedRbac;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use LaravelZero\Framework\Commands\Command;

class GhaConfigureCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithGlobalConfig, InteractsWithProjectConfig, InteractsWithScopedRbac, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'gha:configure {environment? : The environment to configure (production, staging, etc.)}';

    protected $description = 'Configure GitHub Actions secrets using the native GitHub CLI container';

    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->argument('environment') ?: $this->askForCloudEnvironment(
            label: 'Which environment are you configuring GitHub Actions for?',
        );
        $upperEnv = strtoupper($environment);
        $projectPath = getcwd();

        $this->laraKubeInfo("Configuring GitHub Secrets for '{$environment}'...");

        // 1. Check for .env.{env} file
        $envFile = ".env.{$environment}";
        if (! file_exists($projectPath.'/'.$envFile)) {
            $this->laraKubeError("Crucial file '{$envFile}' is missing!");
            $this->line('Please create it before configuring GitHub Actions.');

            return 1;
        }

        $gh = $this->getGhCommand();

        // 2. Auto-detect repository for explicit targeting
        $repo = trim(shell_exec('git remote get-url origin 2>/dev/null') ?? '');
        $repoFlag = '';
        if ($repo) {
            // Convert git@github.com:user/repo.git or github:user/repo.git or https://github.com/user/repo to user/repo
            if (preg_match('/(?:github\.com|github)[:\/](.*?)(?:\.git)?$/', $repo, $matches)) {
                $repoName = $matches[1];
                $repoFlag = "-R {$repoName}";
                $this->info("  🛰 Targeting repository: {$repoName}");
            } else {
                $this->laraKubeWarn("Could not parse GitHub repository name from remote URL: {$repo}");
                $this->line('  The GitHub CLI may fail if it cannot detect the repository.');
            }
        } else {
            $this->laraKubeWarn("No 'origin' git remote found.");
            $this->line('  The GitHub CLI may fail if it cannot detect the repository.');
        }

        // 3. Upload ENV_FILE_BASE64
        $this->laraKubeInfo("Uploading {$upperEnv}_ENV_FILE_BASE64...");
        $envContent = file_get_contents($projectPath.'/'.$envFile);
        if (empty(trim($envContent))) {
            $this->laraKubeError("File '{$envFile}' is empty! Cannot upload an empty environment.");

            return 1;
        }
        $base64Env = base64_encode($envContent);
        $this->info('  📦 Env size: '.strlen($base64Env).' bytes (base64)');

        $this->setGithubSecret($gh, "{$upperEnv}_ENV_FILE_BASE64", $base64Env, $repoFlag);

        // 4. Mint + upload a NAMESPACE-SCOPED kubeconfig (never the admin cert).
        //    The runner becomes a pure consumer of a credential locked to this
        //    env's namespace — a leaked secret can't touch anything else.
        $this->laraKubeInfo("Minting a namespace-scoped KUBECONFIG for {$environment}...");

        $config = $this->getProjectConfigObject($projectPath);

        // Use the env's OWN context to bootstrap (VPS larakube-<ip>, or managed context).
        $adminContext = $this->environmentContextOrCurrent($config, $environment);
        if (! $adminContext) {
            $this->laraKubeError("No cluster target for '{$environment}'.");
            $this->line('  Run <fg=yellow;options=bold>larakube cloud:configure:base '.$environment.'</> (or cloud:provision) first.');

            return 1;
        }

        if (! $this->kubectlSupportsTokens()) {
            $this->laraKubeError('kubectl >= 1.24 is required to mint a scoped token. Please upgrade kubectl.');

            return 1;
        }

        $namespace = $config->getName().'-'.$environment;
        $ctx = escapeshellarg($adminContext);
        $ns = escapeshellarg($namespace);

        // a. Namespace — admin (cluster-scoped). Pre-creating it means the runner
        //    (scoped) only ever applies namespaced resources.
        shell_exec("kubectl --context {$ctx} create namespace {$ns} --dry-run=client -o yaml | kubectl --context {$ctx} apply -f -");

        // b. SA + namespaced Role + RoleBinding (admin, idempotent).
        if (! $this->ensureScopedRbac($adminContext, $namespace, $config->getName(), $environment)) {
            $this->laraKubeError('Failed to create the namespace-scoped ServiceAccount/Role in the cluster.');

            return 1;
        }

        // c. Long-lived Secret-bound token → standalone scoped kubeconfig.
        $kubeConfigContent = $this->mintScopedKubeconfig($adminContext, $namespace);
        if ($kubeConfigContent === null) {
            $this->laraKubeError('Failed to mint the scoped token (the bound-token Secret was never populated).');

            return 1;
        }

        $this->info('  🔒 Scoped to namespace: <fg=cyan>'.$namespace.'</> (a leaked secret can touch nothing else)');
        $this->info('  📦 Kubeconfig size: '.strlen($kubeConfigContent).' bytes');
        if (preg_match('/server: (https:\/\/.*)/', $kubeConfigContent, $matches)) {
            $this->info("  🔗 Server Target: <fg=cyan>{$matches[1]}</>");
        }

        $this->setGithubSecret($gh, "{$upperEnv}_KUBECONFIG", $kubeConfigContent, $repoFlag);

        // d. Stamp when we minted it (lets us warn on stale tokens later).
        $this->stampRbacGranted($projectPath, $config, $environment);

        $this->laraKubeInfo("GitHub Secrets configured successfully for '{$environment}' (namespace-scoped).");

        return 0;
    }

    /** Record the mint time of the scoped CI credential in the blueprint. */
    protected function stampRbacGranted(string $projectPath, ConfigData $config, string $environment): void
    {
        $data = $config->toArray();
        $data['environments'][$environment]['cloud']['rbacGrantedAt'] = gmdate('c');
        ConfigData::from($data)->saveToFile($projectPath);
    }

    protected function ensureEnvironmentExists(string $gh, string $environment, string $repoFlag): void
    {
        $repo = '';
        if (preg_match('/-R (.*)/', $repoFlag, $matches)) {
            $repo = $matches[1];
        }

        if (empty($repo)) {
            return;
        }

        $this->laraKubeInfo("Ensuring GitHub environment '{$environment}' exists...");

        $command = "{$gh} api -X PUT /repos/{$repo}/environments/{$environment} 2>&1";
        exec($command, $output, $resultCode);

        if ($resultCode !== 0) {
            $this->laraKubeWarn("Could not verify/create environment '{$environment}'.");
        }
    }

    protected function setGithubSecret(string $gh, string $name, string $value, string $repoFlag, ?string $environment = null): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'gh_secret');
        file_put_contents($tmpFile, $value);

        $envFlag = $environment ? '--env '.escapeshellarg($environment) : '';
        $command = 'cat '.escapeshellarg($tmpFile)." | {$gh} secret set ".escapeshellarg($name)." {$repoFlag} {$envFlag} 2>&1";
        exec($command, $output, $resultCode);

        @unlink($tmpFile);

        if ($resultCode !== 0) {
            $this->laraKubeError("Failed to set GitHub secret: {$name}");
            $this->line('  Command output:');
            foreach ($output as $line) {
                $this->line("  <fg=red>$line</>");
            }
            exit(1);
        }

        $this->info("  ✅ Secret '{$name}' uploaded successfully.");
    }
}
