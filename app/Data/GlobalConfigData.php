<?php

namespace App\Data;

use App\Enums\AiProvider;
use Illuminate\Support\Carbon;

class GlobalConfigData
{
    const string CONFIG_FILE = 'config.json';

    public function __construct(
        protected ?string $email = null,
        protected string $aiProvider = 'gemini',
        protected array $aiKeys = [],
        protected ?string $lastStarPromptAt = null,
    ) {}

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getAiProvider(): AiProvider
    {
        return AiProvider::tryFrom($this->aiProvider) ?? AiProvider::GEMINI;
    }

    public function setAiProvider(AiProvider|string $aiProvider): void
    {
        $this->aiProvider = $aiProvider instanceof AiProvider ? $aiProvider->value : $aiProvider;
    }

    public function getAiKeys(): array
    {
        return $this->aiKeys;
    }

    public function getAiApiKey(AiProvider|string $provider): ?string
    {
        $key = $provider instanceof AiProvider ? $provider->value : $provider;

        return $this->aiKeys[$key] ?? null;
    }

    public function setAiApiKey(AiProvider|string $provider, string $key): void
    {
        $providerKey = $provider instanceof AiProvider ? $provider->value : $provider;
        $this->aiKeys[$providerKey] = $key;
    }

    public function getLastStarPromptAt(): ?Carbon
    {
        return $this->lastStarPromptAt ? Carbon::parse($this->lastStarPromptAt) : null;
    }

    public function setLastStarPromptAt(Carbon $lastStarPromptAt): void
    {
        $this->lastStarPromptAt = $lastStarPromptAt->toString();
    }

    public static function fromArray(array $data): self
    {
        return new self(
            email: $data['email'] ?? null,
            aiProvider: $data['ai_provider'] ?? 'gemini',
            aiKeys: $data['ai_keys'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'ai_provider' => $this->aiProvider,
            'ai_keys' => $this->aiKeys,
        ];
    }

    public static function load(): self
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        $path = $home.'/.larakube/'.self::CONFIG_FILE;

        if (! file_exists($path)) {
            return new self;
        }

        $data = json_decode(file_get_contents($path), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new self;
        }

        return self::fromArray($data);
    }

    public function save(): void
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        $dir = $home.'/.larakube';
        $path = $dir.'/'.self::CONFIG_FILE;

        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        file_put_contents($path, json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
    }
}
