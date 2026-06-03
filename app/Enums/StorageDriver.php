<?php

namespace App\Enums;

use App\Contracts\AsDependency;
use App\Contracts\HasCommandOptions;
use App\Contracts\HasComposerDependencies;
use App\Contracts\HasDockerImage;
use App\Contracts\HasEnvironmentVariables;
use App\Contracts\HasHosts;
use App\Contracts\HasKubernetesFiles;
use App\Contracts\HasLabel;
use App\Contracts\HasLifecycleHooks;
use App\Contracts\HasPodName;
use App\Contracts\HasPromptableHosts;
use App\Contracts\HasSelectOptions;
use App\Contracts\PlexProvisionable;
use App\Contracts\RemovableWhenManaged;
use App\Data\ConfigData;
use App\Traits\DerivesHostsFromServices;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum StorageDriver: string implements AsDependency, HasCommandOptions, HasComposerDependencies, HasDockerImage, HasEnvironmentVariables, HasHosts, HasKubernetesFiles, HasLabel, HasLifecycleHooks, HasPodName, HasPromptableHosts, HasSelectOptions, PlexProvisionable, RemovableWhenManaged
{
    use DerivesHostsFromServices, GeneratesProjectInfrastructure, ProvidesCommandOptions, ProvidesSelectOptions;

    public function getPodName(?ConfigData $config = null): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::MINIO => 'MinIO (Classic)',
            self::SEAWEEDFS => 'SeaweedFS (High Performance)',
            self::GARAGE => 'Garage (Modern/Rust)',
        };
    }

    public static function getCommandOptionArrays(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = [
                'name' => $case->value,
                'description' => "Use {$case->getLabel()} storage",
            ];
        }

        return $options;
    }

    public function port(): int
    {
        return match ($this) {
            self::MINIO => 9000,
            self::SEAWEEDFS => 8333,
            self::GARAGE => 3900,
        };
    }

    public function consolePort(): int
    {
        return match ($this) {
            self::MINIO => 9001,
            self::SEAWEEDFS => 8888,
            self::GARAGE => 3902,
        };
    }

    public function getDockerImage(?ConfigData $config = null): string
    {
        return match ($this) {
            self::MINIO => 'minio/minio:RELEASE.2025-09-07T16-13-09Z',
            self::SEAWEEDFS => 'chrislusf/seaweedfs:4.20',
            self::GARAGE => 'dxflrs/garage:v2.1.0',
        };
    }

    public function updateK8s(ConfigData $config): void
    {
        $k8sPath = $config->getK8sPath();

        // Write workload
        if ($viewName = $this->getWorkloadViewName()) {
            $dest = $this->getWorkloadYamlDestination();
            if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                $content = view($viewName, ['config' => $config, 'driver' => $this])->render();
                file_put_contents("$k8sPath/{$dest}", $content);
            }
        }

        // Write storage
        if ($viewName = $this->getStorageViewName()) {
            foreach (array_merge(['local'], $config->getCloudEnvironments()) as $env) {
                if (in_array($this->value, $config->getManaged($env), true)) {
                    continue;
                }
                @mkdir("$k8sPath/overlays/$env", 0755, true);
                $dest = "overlays/$env/{$this->getStorageYamlDestination()}";
                if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                    $vols = view($viewName, ['config' => $config, 'driver' => $this, 'environment' => $env])->render();
                    file_put_contents("$k8sPath/overlays/$env/{$this->getStorageYamlDestination()}", $vols);
                }
            }
        }

        // Write network
        if ($viewName = $this->getNetworkViewName()) {
            $dest = $this->getNetworkYamlDestination();
            if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                $ingress = view($viewName, ['config' => $config, 'driver' => $this])->render();
                file_put_contents("$k8sPath/{$dest}", $ingress);
            }
        }
    }

    public function getWorkloadViewName(): ?string
    {
        return match ($this) {
            self::MINIO => 'k8s.minio.deployment',
            self::SEAWEEDFS => 'k8s.seaweedfs.deployment',
            self::GARAGE => 'k8s.garage.deployment',
        };
    }

    public function getWorkloadYamlDestination(): ?string
    {
        return match ($this) {
            self::MINIO => 'base/minio-deployment.yaml',
            self::SEAWEEDFS => 'base/seaweedfs-deployment.yaml',
            self::GARAGE => 'base/garage-deployment.yaml',
        };
    }

    public function getNetworkViewName(): ?string
    {
        return match ($this) {
            self::MINIO => 'k8s.minio.ingress',
            self::SEAWEEDFS => 'k8s.seaweedfs.ingress',
            self::GARAGE => 'k8s.garage.ingress',
        };
    }

    public function getNetworkYamlDestination(): ?string
    {
        return match ($this) {
            self::MINIO => 'overlays/local/minio-ingress.yaml',
            self::SEAWEEDFS => 'overlays/local/seaweedfs-ingress.yaml',
            self::GARAGE => 'overlays/local/garage-ingress.yaml',
        };
    }

    public function getStorageViewName(): ?string
    {
        return match ($this) {
            self::MINIO => 'k8s.minio.volumes',
            self::SEAWEEDFS => 'k8s.seaweedfs.volumes',
            self::GARAGE => 'k8s.garage.volumes',
        };
    }

    public function getStorageYamlDestination(): ?string
    {
        return match ($this) {
            self::MINIO => 'minio-volumes.yaml',
            self::SEAWEEDFS => 'seaweedfs-volumes.yaml',
            self::GARAGE => 'garage-volumes.yaml',
        };
    }

    public function getPatchViewName(): ?string
    {
        return null;
    }

    public function getPatchYamlDestination(): ?string
    {
        return null;
    }

    public function getK8sDeploymentArgs(): string
    {
        return match ($this) {
            self::MINIO => '["server", "/data", "--console-address", ":9001"]',
            self::SEAWEEDFS => '["server", "-dir=/data", "-s3"]',
            self::GARAGE => '["server"]',
        };
    }

    public function getComposerDependencies(?ConfigData $context = null): array
    {
        return ['league/flysystem-aws-s3-v3'];
    }

    public function onPostInstall(string $projectPath, ?ConfigData $context = null): void
    {
        $this->syncEnvFile($projectPath, $this->getEnvironmentVariables($context));

        if ($this === self::GARAGE) {
            // Garage requires explicit key and bucket creation via its CLI.
            // We'll perform this once the infrastructure is up.
            // For now, we'll log it as a post-install instruction or
            // handle it during the first "larakube up".
        }
    }

    public function getEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return array_merge(
            $this->getPublicEnvironmentVariables($config, $environment),
            $this->getSecretEnvironmentVariables($config, $environment),
        );
    }

    public function getPublicEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        $s3Host = $config ? $config->getServiceHost('s3', $environment) : 's3.dev.test';

        $envs = [
            'FILESYSTEM_DISK' => 's3',
            'AWS_ACCESS_KEY_ID' => 'larakube',
            'AWS_DEFAULT_REGION' => 'us-east-1',
            'AWS_BUCKET' => 'laravel',
            'AWS_URL' => 'https://'.$s3Host,
            'AWS_TEMPORARY_URL' => 'https://'.$s3Host,
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        ];

        // INTERNAL endpoint for PHP pod to talk to storage
        $host = $config ? $config->getInternalFqdn($this, $environment) : $this->getPodName();
        $endpoint = match ($this) {
            self::SEAWEEDFS => "http://{$host}:8333",
            self::MINIO => "http://{$host}:9000",
            self::GARAGE => "http://{$host}:3900",
        };

        $envs['AWS_ENDPOINT'] = $endpoint;

        return $envs;
    }

    public function getSecretEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return [
            'AWS_SECRET_ACCESS_KEY' => 'larakubesecretpassword',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getHostServices(): array
    {
        return match ($this) {
            self::MINIO => [
                's3' => 'MinIO S3 API',
                's3-console' => 'MinIO Console',
            ],
            self::SEAWEEDFS => [
                's3' => 'SeaweedFS S3 API',
                's3-admin' => 'SeaweedFS Filer UI',
            ],
            self::GARAGE => [
                's3' => 'Garage S3 API',
                's3-web' => 'Garage Static Web',
            ],
        };
    }

    /**
     * The S3 API endpoint is the client-facing one worth a vanity host
     * (e.g. cdn.example.com); the admin console/filer UI is not prompted.
     *
     * @return array<string, string>
     */
    public function getPromptableHostServices(): array
    {
        return match ($this) {
            self::MINIO => ['s3' => 'MinIO S3 API'],
            self::SEAWEEDFS => ['s3' => 'SeaweedFS S3 API'],
            self::GARAGE => ['s3' => 'Garage S3 API'],
        };
    }

    public function getDependencyConfig(ConfigData $config): array
    {
        return [$this->getPodName($config) => $this->port()];
    }

    public function getPostInstallInstructions(?ConfigData $config = null): array
    {
        return match ($this) {
            self::MINIO => [
                'MinIO requires a one-time bucket creation:',
                '1. Visit the Console: https://'.$config->getServiceHost('s3-console'),
                '2. Login with: larakube / larakubesecretpassword',
                '3. Create a bucket named "laravel"',
            ],
            self::SEAWEEDFS => [
                'SeaweedFS requires a one-time bucket creation after "larakube up":',
                '1. Create Bucket: larakube exec --service=seaweedfs "echo s3.bucket.create -name laravel | /usr/bin/weed shell"',
                'You can monitor your storage at: https://'.$config->getServiceHost('s3-admin'),
            ],
            self::GARAGE => [
                'Garage requires a one-time manual initialization after "larakube up":',
                '1. Get Node ID: larakube exec --service=garage "/garage status"',
                '2. Assign Layout: larakube exec --service=garage "/garage layout assign <ID_PREFIX> --capacity 1GB --zone local --tag default"',
                '3. Apply Layout: larakube exec --service=garage "/garage layout apply --version 1"',
                '4. Create Key: larakube exec --service=garage "/garage key create larakube"',
                '5. Create Bucket: larakube exec --service=garage "/garage bucket create laravel"',
                '6. Link Key/Bucket: larakube exec --service=garage "/garage bucket allow --read --write laravel --key larakube"',
                '7. Update your .env: Copy the machine-generated "Key ID" to AWS_ACCESS_KEY_ID and the "Secret key" to AWS_SECRET_ACCESS_KEY.',
                '8. Sync to cluster: larakube up',
            ],
        };
    }

    public function getManifestFiles(?ConfigData $config = null): array
    {
        $files = [
            'base' => [
                basename($this->getWorkloadYamlDestination()),
            ],
            'local' => [
                basename($this->getStorageYamlDestination()),
                basename($this->getNetworkYamlDestination()),
            ],
            'cloud' => [
                basename($this->getStorageYamlDestination()),
            ],
        ];

        return $files;
    }

    public function getManagedResources(ConfigData $config): array
    {
        $name = $this->getPodName($config);

        return [
            ['kind' => 'Deployment', 'name' => $name],
            ['kind' => 'Service', 'name' => $name],
        ];
    }

    public function getPhpExtensions(): array
    {
        return [];
    }

    public function getDependencies(ConfigData $config): array
    {
        return [];
    }

    public function isPlexReady(): bool
    {
        // Wired Commons S3 backends (deployment + per-tenant bucket provisioning
        // via commonsBucketCreateCommand). Garage is mapped but not wired (#94).
        return match ($this) {
            self::SEAWEEDFS, self::MINIO => true,
            default => false,
        };
    }

    /**
     * Shell command — run via `kubectl exec deploy/<value> -- sh -c '<this>'` —
     * that idempotently creates a tenant's bucket on this Commons S3 backend.
     * Bucket-per-tenant isolation under the shared admin key. The pod's shell
     * expands the credential env vars (MinIO's root user/pass), so they stay out
     * of the local process. Garage isn't wired yet (#94) and fails loudly.
     */
    public function commonsBucketCreateCommand(string $bucket): string
    {
        return match ($this) {
            self::SEAWEEDFS => "echo 's3.bucket.create -name {$bucket}' | weed shell",
            self::MINIO => $this->minioMcCommand('mb --ignore-existing local/'.$bucket),
            self::GARAGE => 'echo "Garage Commons provisioning is not wired yet (#94)" >&2; exit 1',
        };
    }

    /**
     * Inverse of commonsBucketCreateCommand — drops a tenant's bucket (the
     * plex:leave/remove teardown). Same sh -c invocation contract.
     */
    public function commonsBucketDeleteCommand(string $bucket): string
    {
        return match ($this) {
            self::SEAWEEDFS => "echo 's3.bucket.delete -name {$bucket}' | weed shell",
            self::MINIO => $this->minioMcCommand('rb --force local/'.$bucket),
            self::GARAGE => 'echo "Garage Commons provisioning is not wired yet (#94)" >&2; exit 1',
        };
    }

    public function commonsServiceName(): ?string
    {
        // Each S3 backend is its own Commons service (keyed by value), so several
        // can coexist when different tenants declare different backends.
        return $this->value;
    }

    /**
     * A `mc` invocation against the in-pod MinIO. Configures a throwaway alias
     * first (the server image ships `mc` at /usr/bin/mc but unconfigured), using
     * the root creds the Commons deployment injects from the plex-admin Secret.
     * MC_CONFIG_DIR keeps the alias out of an unwritable $HOME.
     */
    private function minioMcCommand(string $mc): string
    {
        return 'export MC_CONFIG_DIR=/tmp/mc; '.
            'mc alias set local http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null 2>&1 && '.
            'mc '.$mc;
    }

    case MINIO = 'minio';
    case SEAWEEDFS = 'seaweedfs';
    case GARAGE = 'garage';
}
