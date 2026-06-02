<?php

namespace App\Commands\Cloud;

use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithRemoteDeploy;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class CloudDeployCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithEnvironments, InteractsWithProjectConfig, InteractsWithRemoteDeploy, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'cloud:deploy {environment? : The environment to deploy to}';

    protected $description = 'Build and deploy the application to a remote environment';

    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->argument('environment') ?: $this->askForCloudEnvironment(
            label: 'Which environment are you deploying to?',
        );

        if ($environment === 'local') {
            $this->laraKubeInfo("For local development, please use 'larakube up'.");

            return 0;
        }

        $this->laraKubeWarn('🚀 MANUAL DEPLOYMENT');
        $this->line('   This command will deploy directly from your local machine to the remote cluster.');
        $this->line('   <fg=gray>Note: GitHub Actions (CI/CD) is the recommended path for professional teams.</>');
        $this->newLine();

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        if ($config->hasGithubActions() && ! $this->option('no-interaction')) {
            $this->info('💡 CI/CD DETECTED: You have GitHub Actions enabled for this project.');
            if (confirm('Would you prefer to trigger a deployment via Git push instead?', true)) {
                $this->laraKubeInfo('Action cancelled. Run "git push" to deploy via GitHub.');

                return 0;
            }
        }

        $appName = $config->getName() ?? basename($projectPath);

        // --- 🌐 WEB DOMAIN GUARD (any cloud env) ---
        // Fires for every non-local env — if user renamed `production` to
        // `main` or added `staging`, we still demand a real web host before
        // pushing to the cluster.
        $currentHost = $config->getHost($environment, 'web');
        $defaultHost = "{$appName}.com";

        if (empty($currentHost) || $currentHost === $defaultHost) {
            $this->newLine();
            $this->warn(" 🌐 WEB DOMAIN REQUIRED FOR '{$environment}'");
            $this->line('   Current web host: <fg=yellow>'.($currentHost ?: '(not set)').'</>');
            $this->newLine();

            $newHost = \Laravel\Prompts\text(
                label: "What is the REAL web domain/subdomain for '{$environment}'?",
                placeholder: $environment === 'production' ? 'myapp.com' : "{$environment}.myapp.com",
                required: true,
            );

            $config->setHost($environment, 'web', $newHost);
            $this->saveProjectConfig($projectPath, $config);

            // Reflect the domain in the env file's APP_URL too. Narrow on purpose
            // (only APP_URL) rather than a full syncEnv, so we never clobber other
            // env values — e.g. a Plex-managed DB_HOST. (The manifest regen below
            // uses syncEnv:false for the same reason.)
            $appUrl = 'https://'.$newHost;
            if ($environment === 'production') {
                $this->syncEnvFile($projectPath, ['APP_URL' => $appUrl], false, true);
            } elseif (file_exists($projectPath.'/.env.'.$environment)) {
                $envPath = $projectPath.'/.env.'.$environment;
                $content = (string) file_get_contents($envPath);
                $content = preg_match('/^#?\s*APP_URL=.*/m', $content)
                    ? preg_replace('/^#?\s*APP_URL=.*/m', 'APP_URL='.$appUrl, $content)
                    : rtrim($content)."\nAPP_URL=".$appUrl."\n";
                file_put_contents($envPath, $content);
            }
        }

        // Keep ASSET_URL aligned with the web domain. @vite prefixes asset URLs
        // with ASSET_URL, so a leaked local "*.dev.test" value sends production
        // assets to the dev host (404 / unstyled). Runs every production deploy
        // (the domain block above is skipped once the host is set) and only
        // rewrites an empty or "*.dev.test" value — never a real CDN/asset host.
        if ($environment === 'production') {
            $this->alignProductionAssetUrl($projectPath, $config->getHost($environment, 'web'));
        }

        // Always regenerate manifests from the blueprint, so a CLI upgrade or a
        // blueprint change is reflected on every deploy — not only when the domain
        // was just set above.
        $this->withSpin('Regenerating manifests from your blueprint...', function () use ($config) {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false, syncEnv: false);

            return true;
        });

        // Resolve the env's deploy target. It lives in .larakube.json
        // (environments.{env}.cloud); if it's not recorded yet (e.g. the server
        // was provisioned before this was persisted), ask once and save it — so
        // the target is in the blueprint and future deploys are zero-prompt. The
        // env's OWN kube-context is derived from it (larakube-<ip>), never the
        // global current-context, so local dev pointed elsewhere is undisturbed.
        $cloud = $config->getCloud($environment);
        if (! $cloud || ! $cloud->ip) {
            $config = $this->captureCloudConnection($config, $environment, $projectPath);
            $cloud = $config->getCloud($environment);
        }

        $this->laraKubeInfo("Deploying '{$appName}' to '{$environment}' on context '".$this->remoteContextName($cloud->ip)."'.");
        $this->line('   <fg=gray>Builds locally, sideloads the image into the remote node (no registry), applies manifests.</>');
        $this->newLine();

        if (! confirm('Proceed?', true)) {
            $this->laraKubeInfo('Deployment cancelled.');

            return 0;
        }

        return $this->deployViaSshSideload($config, $environment);
    }
}
