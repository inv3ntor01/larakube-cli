<?php

namespace App\Traits;

use App\Data\GlobalConfigData;
use App\Enums\AiProvider;

trait InteractsWithGlobalConfig
{
    protected function getGlobalConfig(): GlobalConfigData
    {
        return GlobalConfigData::load();
    }

    protected function getGhConfigPath(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');

        return $home.'/.larakube/gh-config';
    }

    protected function getGhDockerCommand(?string $workDir = null): string
    {
        $workDir = $workDir ?? getcwd();
        $configPath = $this->getGhConfigPath();

        if (! is_dir($configPath)) {
            @mkdir($configPath, 0700, true);
        }

        return 'docker run --rm -it '.
               "-v {$workDir}:/work ".
               "-v {$configPath}:/root/.config/gh ".
               '-w /work '.
               'ghcr.io/cli/cli ';
    }

    protected function getEmail(): ?string
    {
        return $this->getGlobalConfig()->getEmail();
    }

    protected function getDefaultEmail(): string
    {
        return 'admin@larakube.dev.test';
    }

    protected function setEmail(string $email): void
    {
        $config = $this->getGlobalConfig();
        $config->setEmail($email);
        $config->save();
    }

    protected function getAiProvider(): AiProvider
    {
        return $this->getGlobalConfig()->getAiProvider();
    }

    protected function setAiProvider(AiProvider|string $provider): void
    {
        $config = $this->getGlobalConfig();
        $config->setAiProvider($provider);
        $config->save();
    }

    protected function getAiApiKey(AiProvider|string|null $provider = null): ?string
    {
        $config = $this->getGlobalConfig();
        $provider = $provider ?? $config->getAiProvider();

        $providerName = $provider instanceof AiProvider ? $provider->value : $provider;

        return $config->getAiApiKey($provider) ?? env(strtoupper($providerName).'_API_KEY');
    }

    protected function setAiApiKey(string $key, AiProvider|string|null $provider = null): void
    {
        $config = $this->getGlobalConfig();
        $provider = $provider ?? $config->getAiProvider();
        $config->setAiApiKey($provider, $key);
        $config->save();
    }

    protected function checkCaTrust(): bool
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Darwin') {
            $output = shell_exec('security find-certificate -c "Server Side Up CA" 2>/dev/null');

            return ! empty($output);
        }

        if ($os === 'Linux') {
            return file_exists('/usr/local/share/ca-certificates/larakube-local-ca.crt');
        }

        return false;
    }
}
