<?php

// Mock shell_exec in the App\Traits namespace

namespace App\Traits {
    function shell_exec($command)
    {
        if (array_key_exists('mock_shell_exec_output', $GLOBALS)) {
            return $GLOBALS['mock_shell_exec_output'];
        }

        return \shell_exec($command);
    }
}

namespace Tests\Feature {
    use App\Traits\InteractsWithClusterContext;

    $testClass = new class
    {
        use InteractsWithClusterContext;

        public function testIsLocalContext(): bool
        {
            return $this->isLocalContext();
        }
    };

    test('isLocalContext identifies local clusters', function () use ($testClass) {
        $localContexts = ['k3d-larakube', 'minikube', 'docker-desktop', 'orbstack', 'kind-cluster', 'colima'];

        foreach ($localContexts as $context) {
            $GLOBALS['mock_shell_exec_output'] = $context;
            expect($testClass->testIsLocalContext())->toBeTrue("Failed for context: $context");
        }
    });

    test('isLocalContext identifies remote clusters', function () use ($testClass) {
        $remoteContexts = ['gke_project_zone_cluster', 'arn:aws:eks:us-west-2:123456789012:cluster/prod', 'do-nyc1-my-cluster'];

        foreach ($remoteContexts as $context) {
            $GLOBALS['mock_shell_exec_output'] = $context;
            expect($testClass->testIsLocalContext())->toBeFalse("Failed for context: $context");
        }
    });

    test('isLocalContext returns false if shell_exec fails', function () use ($testClass) {
        $GLOBALS['mock_shell_exec_output'] = null;
        expect($testClass->testIsLocalContext())->toBeFalse();
    });
}
