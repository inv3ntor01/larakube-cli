<?php

namespace App\Data;

use App\Enums\AiProvider;
use App\Traits\InteractsWithJsonFile;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use stdClass;

class GlobalConfigData extends Data
{
    use InteractsWithJsonFile;

    const string CONFIG_FILE = 'config.json';

    const string DEFAULT_TLD = 'kube';

    /** Valid TLDs the user may choose. */
    const array ALLOWED_TLDS = ['kube', 'localhost', 'test', 'local', 'internal'];

    public function __construct(
        public ?string $email = null,
        public string $aiProvider = 'anthropic',
        public array $aiKeys = [],
        public ?string $lastStarPromptAt = null,
        public string $localTld = self::DEFAULT_TLD,
        /** Cloudflare named-tunnel token reused across share sessions (optional). */
        public ?string $shareToken = null,
        /**
         * Per-app named-tunnel URLs stored after the first `larakube share` with a token.
         * Shape: ['appname' => ['web' => 'https://…', 'hmr' => 'https://…', 'storage' => 'https://…']]
         *
         * @var array<string, array<string, string>>
         */
        public array $shareUrls = [],
    ) {}

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getLocalTld(): string
    {
        return $this->localTld ?: self::DEFAULT_TLD;
    }

    public function setLocalTld(string $tld): void
    {
        $this->localTld = ltrim(strtolower(trim($tld)), '.');
    }

    public function getAiProvider(): AiProvider
    {
        return AiProvider::tryFrom($this->aiProvider) ?? AiProvider::ANTHROPIC;
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

    public function getShareToken(): ?string
    {
        return $this->shareToken;
    }

    public function setShareToken(?string $token): void
    {
        $this->shareToken = $token;
    }

    /** Stored named-tunnel URLs for one app (returns empty array if not yet configured). */
    public function getShareUrls(string $appName): array
    {
        return $this->shareUrls[$appName] ?? [];
    }

    /** Persist named-tunnel URLs for one app (merges with any existing keys). */
    public function setShareUrls(string $appName, array $urls): void
    {
        $this->shareUrls[$appName] = array_filter(array_merge(
            $this->shareUrls[$appName] ?? [],
            $urls,
        ));
    }

    public static function load(): self
    {
        $path = home_path('.larakube/'.self::CONFIG_FILE);

        $data = self::readJsonFile($path);

        return $data === null ? new self : self::from($data);
    }

    public function save(): void
    {
        $dir = home_path('.larakube');
        $path = $dir.'/'.self::CONFIG_FILE;

        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $data = $this->toArray();
        if (empty($data['shareUrls'])) {
            $data['shareUrls'] = new stdClass; // {} not [] in JSON
        }

        self::atomicWriteJson($path, $data, 0600);
    }
}
