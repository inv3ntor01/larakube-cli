<?php

namespace App\Traits;

trait InteractsWithGlobalConfig
{
    protected function getGlobalConfigPath(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');

        return $home.'/.larakube/config.json';
    }

    protected function getGlobalConfig(): array
    {
        $path = $this->getGlobalConfigPath();
        if (file_exists($path)) {
            return json_decode(file_get_contents($path), true) ?? [];
        }

        return [];
    }

    protected function setGlobalConfig(array $config): void
    {
        $path = $this->getGlobalConfigPath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT));
        @chmod($path, 0600); // Secure the file
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
        return $this->getGlobalConfig()['email'] ?? null;
    }

    protected function setEmail(string $email): void
    {
        $config = $this->getGlobalConfig();
        $config['email'] = $email;
        $this->setGlobalConfig($config);
    }

    protected function getAiProvider(): string
    {
        return $this->getGlobalConfig()['ai_provider'] ?? 'gemini';
    }

    protected function setAiProvider(string $provider): void
    {
        $config = $this->getGlobalConfig();
        $config['ai_provider'] = $provider;
        $this->setGlobalConfig($config);
    }

    protected function getAiApiKey(?string $provider = null): ?string
    {
        $provider = $provider ?? $this->getAiProvider();
        $config = $this->getGlobalConfig();

        return $config['ai_keys'][$provider] ?? env(strtoupper($provider).'_API_KEY');
    }

    protected function setAiApiKey(string $key, ?string $provider = null): void
    {
        $provider = $provider ?? $this->getAiProvider();
        $config = $this->getGlobalConfig();
        $config['ai_keys'][$provider] = $key;
        $this->setGlobalConfig($config);
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
