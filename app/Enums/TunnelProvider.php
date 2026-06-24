<?php

namespace App\Enums;

enum TunnelProvider: string
{
    public function getLabel(): string
    {
        return match ($this) {
            self::CLOUDFLARE => 'Cloudflare Tunnel',
            self::LOCALTONET => 'Localtonet',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::CLOUDFLARE => 'Named tunnel via Cloudflare Zero Trust (recommended — free tier available)',
            self::LOCALTONET => 'Localtonet tunnel — alternative for regions without Cloudflare WARP',
        };
    }

    public function getImage(): string
    {
        return match ($this) {
            self::CLOUDFLARE => 'cloudflare/cloudflared:latest',
            self::LOCALTONET => 'localtonet/localtonet:latest',
        };
    }

    /** Container args / command used to run the tunnel. TOKEN is injected via env var. */
    public function getArgs(): array
    {
        return match ($this) {
            self::CLOUDFLARE => ['tunnel', '--no-autoupdate', 'run', '--token', '$(TUNNEL_TOKEN)'],
            self::LOCALTONET => ['/app/localtonet', '--authtoken', '$(TUNNEL_TOKEN)'],
        };
    }

    /** Whether this provider exposes a health endpoint we can probe. */
    public function hasHealthProbe(): bool
    {
        return $this === self::CLOUDFLARE;
    }

    /** Env var the token is conventionally passed in from the shell during configure. */
    public function envVarName(): string
    {
        return match ($this) {
            self::CLOUDFLARE => 'CLOUDFLARE_TUNNEL_TOKEN',
            self::LOCALTONET => 'LOCALTONET_AUTH_TOKEN',
        };
    }

    public function tokenPromptLabel(): string
    {
        return match ($this) {
            self::CLOUDFLARE => 'Cloudflare Tunnel token (from Zero Trust → Tunnels → Create a tunnel)',
            self::LOCALTONET => 'Localtonet auth token (from localtonet.com → Authtoken)',
        };
    }
    case CLOUDFLARE = 'cloudflare';
    case LOCALTONET = 'localtonet';
}
