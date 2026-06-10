<?php

test('bundle:zip compresses a directory into a tarball and bundle:unzip extracts it', function () {
    $tmpDir = sys_get_temp_dir().'/larakube-bundle-test-'.uniqid();
    mkdir($tmpDir, 0755, true);
    mkdir("$tmpDir/dist", 0755, true);
    $bundleDir = "$tmpDir/dist/test-bundle";
    mkdir($bundleDir, 0755, true);

    // Create a mock bundle.json and .env
    file_put_contents("$bundleDir/bundle.json", '{"name":"test"}');
    file_put_contents("$bundleDir/.env", 'TEST_KEY=123');

    $originalCwd = getcwd();
    chdir($tmpDir);

    try {
        // Test bundle:zip
        $this->artisan('bundle:zip', [
            'path' => 'dist/test-bundle',
            '--output' => 'dist/my-bundle',
            '--delete' => true,
        ])->assertExitCode(0);

        // Verify the original directory was deleted (--delete)
        expect(is_dir($bundleDir))->toBeFalse();
        // Verify the tarball was created with the custom output name
        expect(file_exists("$tmpDir/dist/my-bundle.tar.gz"))->toBeTrue();

        // Test bundle:unzip
        $this->artisan('bundle:unzip', [
            'path' => 'dist/my-bundle.tar.gz',
            '--delete' => true,
        ])->assertExitCode(0);

        // The tarball contained the folder 'test-bundle', so it should be recreated
        expect(is_dir($bundleDir))->toBeTrue();
        expect(file_get_contents("$bundleDir/.env"))->toBe('TEST_KEY=123');
        // The archive should be deleted because of --delete
        expect(file_exists("$tmpDir/dist/my-bundle.tar.gz"))->toBeFalse();

    } finally {
        chdir($originalCwd);
        // Cleanup
        shell_exec('rm -rf '.escapeshellarg($tmpDir));
    }
});
