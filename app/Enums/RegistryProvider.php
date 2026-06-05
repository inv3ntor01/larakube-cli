<?php

namespace App\Enums;

enum RegistryProvider: string
{
    public function label(): string
    {
        return match ($this) {
            self::GHCR => 'GitHub Container Registry (GHCR)',
            self::DOCKERHUB => 'Docker Hub',
        };
    }

    public function registryHost(): string
    {
        return match ($this) {
            self::GHCR => 'ghcr.io',
            self::DOCKERHUB => 'docker.io',
        };
    }

    public function defaultImagePath(string $githubRepo): string
    {
        return match ($this) {
            self::GHCR => $githubRepo,  // ghcr.io/{owner}/{repo}
            self::DOCKERHUB => $githubRepo,  // docker.io/{owner}/{repo}
        };
    }
    case GHCR = 'ghcr';
    case DOCKERHUB = 'dockerhub';
}
