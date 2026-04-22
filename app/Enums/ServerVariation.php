<?php

namespace App\Enums;

enum ServerVariation: string
{
    case FPM_NGINX = 'fpm-nginx';
    case FRANKENPHP = 'frankenphp';
    case FPM_APACHE = 'fpm-apache';

    public function label(): string
    {
        return match ($this) {
            self::FPM_NGINX => 'PHP-FPM + NGINX (Traditional, widely adopted)',
            self::FRANKENPHP => 'FrankenPHP (Laravel Octane, worker mode, HTTP/2 & HTTP/3)',
            self::FPM_APACHE => 'PHP-FPM + Apache (Ideal for WordPress, .htaccess support)',
        };
    }

    public function containerPort(): int
    {
        return match ($this) {
            self::FRANKENPHP => 8080,
            default => 8443,
        };
    }

    public function traefikScheme(): string
    {
        return match ($this) {
            self::FRANKENPHP => 'http',
            default => 'https',
        };
    }
}
