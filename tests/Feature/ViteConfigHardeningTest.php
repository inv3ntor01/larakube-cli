<?php

use App\Data\ConfigData;
use Tests\Feature\ViteHardenHelper;

test('Vite Hardening: Strips Wayfinder and injects K8s config', function () {
    $tempDir = sys_get_temp_dir().'/vite-harden-test-'.uniqid();
    mkdir($tempDir, 0755, true);

    $viteConfig = <<<'TS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { wayfinder } from 'wayfinder-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        wayfinder(),
    ],
});
TS;

    file_put_contents($tempDir.'/vite.config.ts', $viteConfig);

    $config = new ConfigData(name: 'test-app');
    $config->setPath($tempDir);
    $config->setIsScaffolding(true);

    (new ViteHardenHelper)->hardenViteConfig($config);

    $result = file_get_contents($tempDir.'/vite.config.ts');

    // Verify Wayfinder is gone
    expect($result)->not->toContain('wayfinder');

    // Verify K8s config is injected
    expect($result)->toContain("origin: 'https://vite-test-app.dev.test'");
    expect($result)->toContain("host: 'vite-test-app.dev.test'");
    expect($result)->toContain('cors: true');

    exec('rm -rf '.escapeshellarg($tempDir));
});

test('Vite Hardening: Handles Inertia SSR disabling', function () {
    $tempDir = sys_get_temp_dir().'/vite-ssr-test-'.uniqid();
    mkdir($tempDir, 0755, true);

    $viteConfig = <<<'TS'
import { defineConfig } from 'vite';
import inertia from '@inertiajs/vite';

export default defineConfig({
    plugins: [
        inertia(),
    ],
});
TS;

    file_put_contents($tempDir.'/vite.config.ts', $viteConfig);

    $config = new ConfigData(name: 'test-app');
    $config->setPath($tempDir);
    $config->setIsScaffolding(true);

    (new ViteHardenHelper)->hardenViteConfig($config);

    $result = file_get_contents($tempDir.'/vite.config.ts');

    // Verify Inertia SSR is disabled
    expect($result)->toContain('inertia({ ssr: false })');

    exec('rm -rf '.escapeshellarg($tempDir));
});

test('Vite Hardening: injects optimizeDeps so HMR does not hard-reload mid-session', function () {
    $tempDir = sys_get_temp_dir().'/vite-optimize-test-'.uniqid();
    mkdir($tempDir, 0755, true);

    $viteConfig = <<<'TS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/js/app.ts'], refresh: true }),
    ],
});
TS;

    file_put_contents($tempDir.'/vite.config.ts', $viteConfig);

    $config = new ConfigData(name: 'test-app');
    $config->setPath($tempDir);
    $config->setIsScaffolding(true);

    (new ViteHardenHelper)->hardenViteConfig($config);

    $result = file_get_contents($tempDir.'/vite.config.ts');

    expect($result)->toContain('optimizeDeps:')
        ->and($result)->toContain("entries: ['resources/js/**/*.{js,ts,jsx,tsx,vue,svelte}']");

    // Idempotent: a second pass must not add a duplicate block.
    (new ViteHardenHelper)->hardenViteConfig($config);
    expect(substr_count(file_get_contents($tempDir.'/vite.config.ts'), 'optimizeDeps:'))->toBe(1);

    exec('rm -rf '.escapeshellarg($tempDir));
});

test('Vite Hardening: adds optimizeDeps to an already-K8s-ready config (heal path)', function () {
    $tempDir = sys_get_temp_dir().'/vite-optimize-existing-'.uniqid();
    mkdir($tempDir, 0755, true);

    // Already hardened for K8s (server block, host + cors) but predating
    // optimizeDeps — exactly the app-two/app-three case on `heal`/regeneration.
    $viteConfig = <<<'TS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    server: {
        cors: true,
        origin: 'https://vite-test-app.dev.test',
        hmr: { host: 'vite-test-app.dev.test' },
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
    },
    plugins: [
        laravel({ input: ['resources/js/app.ts'], refresh: true }),
    ],
});
TS;

    file_put_contents($tempDir.'/vite.config.ts', $viteConfig);

    $config = new ConfigData(name: 'test-app');
    $config->setPath($tempDir);
    // NOT scaffolding — a heal of an existing project.

    (new ViteHardenHelper)->hardenViteConfig($config);

    $result = file_get_contents($tempDir.'/vite.config.ts');

    expect($result)->toContain('optimizeDeps:')
        // The existing server block must be preserved untouched.
        ->and($result)->toContain("origin: 'https://vite-test-app.dev.test'");

    exec('rm -rf '.escapeshellarg($tempDir));
});
