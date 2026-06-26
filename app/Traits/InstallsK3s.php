<?php

namespace App\Traits;

use App\Data\ConfigData;

/**
 * One place for how k3s is installed, shared by the local installer (cluster:setup) and
 * the remote installer (cloud:provision). Both pipe the official get.k3s.io script; only
 * the execution context differs (local passthru vs SSH) and their own pre/post steps
 * (kubeconfig merge locally; swap + IP-forward remotely). Centralising the URL, the pinned
 * version, and the base invocation keeps the two from silently drifting apart.
 */
trait InstallsK3s
{
    /**
     * The pinned k3s version — single source of truth (ConfigData::DEFAULT_K3S_VERSION).
     * Honors a project override when a config is passed, else the default.
     */
    protected function k3sVersion(?ConfigData $config = null): string
    {
        return $config?->k3sVersion ?? ConfigData::DEFAULT_K3S_VERSION;
    }

    /**
     * The canonical k3s install command:
     *   curl -sfL https://get.k3s.io | <env> INSTALL_K3S_VERSION=<v> sh [-s - <flags>]
     * Callers wrap it in their own execution context (local passthru vs remote SSH).
     *
     * @param  array<int, string>  $flags  install args appended after `sh -s -` (e.g. --disable=traefik). Static/controlled — not escaped.
     * @param  array<string, string>  $env  extra env assignments prepended (e.g. K3S_KUBECONFIG_MODE => 644)
     */
    protected function k3sInstallCommand(string $version, array $flags = [], array $env = []): string
    {
        $assignments = 'INSTALL_K3S_VERSION='.escapeshellarg($version).' ';
        foreach ($env as $key => $value) {
            $assignments .= $key.'='.escapeshellarg($value).' ';
        }

        $sh = $flags === [] ? 'sh -' : 'sh -s - '.implode(' ', $flags);

        return 'curl -sfL https://get.k3s.io | '.$assignments.$sh;
    }
}
