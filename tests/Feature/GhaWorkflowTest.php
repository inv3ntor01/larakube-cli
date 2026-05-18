<?php

use App\Data\ConfigData;
use App\Enums\PackageManager;

test('GHA workflow generation uses correct literal injection syntax', function () {
    $config = new ConfigData(name: 'test-app');
    $config->setPackageManager(PackageManager::NPM);
    $environment = 'production';
    $appName = 'test-app';
    $namespace = 'test-app-production';
    $podName = 'web';
    $upperEnv = 'PRODUCTION';
    $branch = 'main';

    $workflowContent = view('k8s.cloud-pilot-deploy', [
        'config' => $config,
        'environment' => $environment,
        'branch' => $branch,
        'appName' => $appName,
        'namespace' => $namespace,
        'podName' => $podName,
        'upperEnv' => $upperEnv,
        'secrets' => [
            'k_env' => '${{ secrets.'.$upperEnv.'_KUBECONFIG }}',
            'k_base' => '${{ secrets.KUBECONFIG }}',
            'e_env' => '${{ secrets.'.$upperEnv.'_ENV_FILE_BASE64 }}',
            'e_base' => '${{ secrets.ENV_FILE_BASE64 }}',
        ],
        'gha' => [
            'repository' => '${{ github.repository }}',
            'actor' => '${{ github.actor }}',
            'token' => '${{ secrets.GITHUB_TOKEN }}',
            'sha' => '${{ github.sha }}',
            'registry' => '${{ env.REGISTRY }}',
            'image_name' => '${{ env.IMAGE_NAME }}',
            'k_data' => '${{ env.K_DATA }}',
            'e_data' => '${{ env.E_DATA }}',
        ],
    ])->render();

    // Verify Literal Injections
    expect($workflowContent)->toContain('FINAL_KUBE="${{ secrets.PRODUCTION_KUBECONFIG }}"');
    expect($workflowContent)->toContain('FINAL_ENV="${{ secrets.PRODUCTION_ENV_FILE_BASE64 }}"');
    expect($workflowContent)->toContain('IMAGE_NAME: ${{ github.repository }}');
    expect($workflowContent)->toContain('kubeconfig: ${{ env.K_DATA }}');

    // Verify no Blade '@' symbols leaked into the GitHub syntax
    expect($workflowContent)->not->toContain('@{{');

    // Verify no unresolved variable placeholders
    expect($workflowContent)->not->toContain('{{ $upperEnv }}');
});
