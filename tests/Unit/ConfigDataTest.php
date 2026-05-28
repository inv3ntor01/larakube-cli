<?php

namespace Tests\Unit;

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use App\Enums\FrontendStack;
use App\Enums\IngressController;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PhpVersion;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use Tests\TestCase;

class ConfigDataTest extends TestCase
{
    public function test_it_casts_strings_to_enums_correctly()
    {
        $data = [
            'blueprints' => ['statamic'],
            'serverVariation' => 'fpm-nginx',
            'frontend' => 'react',
            'phpVersion' => '8.5',
            'os' => 'alpine',
            'strategy' => 'single-node',
        ];

        $config = ConfigData::from($data);

        // Single Enums
        $this->assertEquals(ServerVariation::FPM_NGINX, $config->serverVariation);
        $this->assertEquals(FrontendStack::REACT, $config->frontend);
        $this->assertEquals(PhpVersion::PHP_8_5, $config->phpVersion);
        $this->assertEquals(OperatingSystem::ALPINE, $config->os);
        $this->assertEquals(DeploymentStrategy::SINGLE_NODE, $config->strategy);

        // Array of Enums
        $this->assertIsArray($config->blueprints);
        $this->assertEquals(Blueprint::STATAMIC, $config->blueprints[0]);
    }

    public function test_it_handles_multiple_enum_values_in_arrays()
    {
        $data = [
            'databases' => ['mysql', 'postgres'],
            'cacheDrivers' => ['redis'],
            'features' => ['horizon', 'reverb'],
        ];

        $config = ConfigData::from($data);

        $this->assertCount(2, $config->databases);
        $this->assertEquals(DatabaseDriver::MYSQL, $config->databases[0]);
        $this->assertEquals(DatabaseDriver::POSTGRESQL, $config->databases[1]);

        $this->assertCount(1, $config->cacheDrivers);
        $this->assertEquals(CacheDriver::REDIS, $config->cacheDrivers[0]);

        $this->assertCount(2, $config->features);
        $this->assertEquals(LaravelFeature::HORIZON, $config->features[0]);
        $this->assertEquals(LaravelFeature::REVERB, $config->features[1]);
    }

    public function test_it_maintains_default_values()
    {
        $config = ConfigData::from([]);

        $this->assertEquals(DeploymentStrategy::SINGLE_NODE, $config->strategy);
        $this->assertSame(['local', 'production'], $config->getEnvironments());
        $this->assertInstanceOf(EnvironmentData::class, $config->getEnvironment('local'));
        $this->assertInstanceOf(EnvironmentData::class, $config->getEnvironment('production'));
        $this->assertEquals(IngressController::TRAEFIK, $config->getIngress('local'));
        $this->assertEquals(IngressController::TRAEFIK, $config->getIngress('production'));
        $this->assertTrue($config->githubActions);
        $this->assertFalse($config->isSystem);
        $this->assertFalse($config->isScaffolding);
    }

    public function test_environments_are_promoted_from_json_array_shape()
    {
        $config = ConfigData::from([
            'environments' => [
                'local' => ['managed' => [], 'hosts' => []],
                'production' => [
                    'managed' => ['postgres', 'redis'],
                    'hosts' => ['web' => 'example.com'],
                ],
            ],
        ]);

        $this->assertInstanceOf(EnvironmentData::class, $config->getEnvironment('production'));
        $this->assertSame(['postgres', 'redis'], $config->getManaged('production'));
        $this->assertSame([], $config->getManaged('local'));
        $this->assertSame('example.com', $config->getEnvironment('production')->hosts['web']);
        $this->assertSame('https://example.com', $config->getAppUrl('production'));
    }

    public function test_features_filter_by_env_with_enum_defaults()
    {
        // BOOST + MCP default to local only; HORIZON to all envs; SSR to prod only.
        $config = ConfigData::from([
            'features' => ['boost', 'mcp', 'horizon', 'ssr'],
        ]);

        $local = $config->getFeatures('local');
        $this->assertContains(LaravelFeature::BOOST, $local);
        $this->assertContains(LaravelFeature::MCP, $local);
        $this->assertContains(LaravelFeature::HORIZON, $local);
        $this->assertNotContains(LaravelFeature::SSR, $local);

        $prod = $config->getFeatures('production');
        $this->assertContains(LaravelFeature::HORIZON, $prod);
        $this->assertContains(LaravelFeature::SSR, $prod);
        $this->assertNotContains(LaravelFeature::BOOST, $prod);
        $this->assertNotContains(LaravelFeature::MCP, $prod);
    }

    public function test_environment_overrides_can_add_or_exclude_features()
    {
        $config = ConfigData::from([
            'features' => ['horizon', 'boost'],
            'environments' => [
                'local' => [
                    'excludeFeatures' => ['horizon'],
                ],
                'production' => [
                    'addFeatures' => ['boost'],
                ],
            ],
        ]);

        $this->assertNotContains(LaravelFeature::HORIZON, $config->getFeatures('local'));
        $this->assertContains(LaravelFeature::BOOST, $config->getFeatures('local'));
        $this->assertContains(LaravelFeature::HORIZON, $config->getFeatures('production'));
        $this->assertContains(LaravelFeature::BOOST, $config->getFeatures('production'));
    }

    public function test_set_production_host_writes_into_environment_map()
    {
        $config = ConfigData::from([]);
        $config->setProductionHost('app.example.com');

        $this->assertSame('app.example.com', $config->getProductionHost());
        $this->assertSame('app.example.com', $config->getEnvironment('production')->hosts['web']);
    }

    public function test_each_environment_can_choose_its_own_ingress_controller()
    {
        $config = ConfigData::from([
            'environments' => [
                'local' => [],
                'staging' => ['ingress' => 'traefik'],
                'qa' => ['ingress' => 'nginx'],
                'production' => ['ingress' => 'aws-alb'],
            ],
        ]);

        $this->assertEquals(IngressController::TRAEFIK, $config->getIngress('local'));
        $this->assertEquals(IngressController::TRAEFIK, $config->getIngress('staging'));
        $this->assertEquals(IngressController::NGINX, $config->getIngress('qa'));
        $this->assertEquals(IngressController::AWS_ALB, $config->getIngress('production'));
    }

    public function test_get_ingress_defaults_to_traefik_for_unconfigured_environments()
    {
        $config = ConfigData::from(['environments' => ['local' => [], 'production' => []]]);

        $this->assertEquals(IngressController::TRAEFIK, $config->getIngress('local'));
        $this->assertEquals(IngressController::TRAEFIK, $config->getIngress('production'));
    }

    public function test_build_wait_for_command()
    {
        // 1. System projects skip external TCP checks but still return null without waitForWeb
        $config = ConfigData::from(['isSystem' => true]);
        $this->assertNull($config->buildWaitForCommand([DatabaseDriver::MYSQL]));

        // 2. System project WITH waitForWeb=true returns curl check (not TCP check)
        $command = $config->buildWaitForCommand([DatabaseDriver::MYSQL], waitForWeb: true);
        $this->assertStringContainsString('curl -sf http://web/up', $command);
        $this->assertStringNotContainsString('mysql', $command);

        // 3. Normal project with MySQL returns nc command
        $config = ConfigData::from(['isSystem' => false]);
        $command = $config->buildWaitForCommand([DatabaseDriver::MYSQL]);
        $this->assertStringContainsString('mysql 3306', $command);

        // 4. Normal project with MySQL + waitForWeb includes BOTH checks
        $command = $config->buildWaitForCommand([DatabaseDriver::MYSQL], waitForWeb: true);
        $this->assertStringContainsString('curl -sf http://web/up', $command);
        $this->assertStringContainsString('mysql 3306', $command);

        // 5. SQLite returns null (no external service)
        $this->assertNull($config->buildWaitForCommand([DatabaseDriver::SQLITE]));

        // 6. SQLite + waitForWeb returns only curl check
        $command = $config->buildWaitForCommand([DatabaseDriver::SQLITE], waitForWeb: true);
        $this->assertStringContainsString('curl -sf http://web/up', $command);
        $this->assertStringNotContainsString('sqlite', $command);

        // 7. Redis cache returns command on port 6379
        $command = $config->buildWaitForCommand([CacheDriver::REDIS]);
        $this->assertStringContainsString('redis 6379', $command);

        // 8. Memcached cache returns command on port 11211
        $command = $config->buildWaitForCommand([CacheDriver::MEMCACHED]);
        $this->assertStringContainsString('memcached 11211', $command);

        // 9. Database cache returns null
        $this->assertNull($config->buildWaitForCommand([CacheDriver::DATABASE]));

        // 10. Meilisearch scout returns command on port 7700
        $command = $config->buildWaitForCommand([ScoutDriver::MEILISEARCH]);
        $this->assertStringContainsString('meilisearch 7700', $command);

        // 11. Typesense scout returns command on port 8108
        $command = $config->buildWaitForCommand([ScoutDriver::TYPESENSE]);
        $this->assertStringContainsString('typesense 8108', $command);

        // 12. Database scout returns null
        $this->assertNull($config->buildWaitForCommand([ScoutDriver::DATABASE]));

        // 13. Storage drivers return command on correct ports
        $command = $config->buildWaitForCommand([StorageDriver::MINIO]);
        $this->assertStringContainsString('minio 9000', $command);

        $command = $config->buildWaitForCommand([StorageDriver::SEAWEEDFS]);
        $this->assertStringContainsString('seaweedfs 8333', $command);

        $command = $config->buildWaitForCommand([StorageDriver::GARAGE]);
        $this->assertStringContainsString('garage 3900', $command);
    }

    public function test_is_scaffolding_getter_works_as_method()
    {
        // Ensures isScaffolding() can be called as a method (not just as a property).
        // This would have caught the BadMethodCallException thrown during `larakube new`.
        $config = ConfigData::from(['isScaffolding' => false]);
        $this->assertFalse($config->isScaffolding());

        $config->setIsScaffolding(true);
        $this->assertTrue($config->isScaffolding());
    }

    public function test_php_version_is_hidden_respects_scaffolding()
    {
        // Ensures PhpVersion::isHidden() can call $config->isScaffolding() without crashing.
        // This would have caught the BadMethodCallException thrown during `larakube new`.
        $scaffolding = ConfigData::from(['isScaffolding' => true]);

        // Old versions should be hidden when scaffolding a new project (Laravel 13 requires 8.3+)
        $this->assertTrue(PhpVersion::PHP_8_2->isHidden($scaffolding));
        $this->assertTrue(PhpVersion::PHP_8_1->isHidden($scaffolding));
        $this->assertTrue(PhpVersion::PHP_8_0->isHidden($scaffolding));
        $this->assertTrue(PhpVersion::PHP_7_4->isHidden($scaffolding));

        // Modern versions must remain visible when scaffolding
        $this->assertFalse(PhpVersion::PHP_8_5->isHidden($scaffolding));
        $this->assertFalse(PhpVersion::PHP_8_4->isHidden($scaffolding));
        $this->assertFalse(PhpVersion::PHP_8_3->isHidden($scaffolding));

        // Without scaffolding, old versions are visible
        $existing = ConfigData::from(['isScaffolding' => false]);
        $this->assertFalse(PhpVersion::PHP_8_2->isHidden($existing));
        $this->assertFalse(PhpVersion::PHP_8_1->isHidden($existing));
    }

    public function test_watch_paths_default_to_standard_laravel_dirs()
    {
        $config = ConfigData::from([]);

        $this->assertSame(
            ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'composer.lock', '.env'],
            $config->getWatchPaths(),
        );
    }

    public function test_watch_paths_can_be_overridden_via_blueprint()
    {
        $config = ConfigData::from([
            'watchPaths' => ['app', 'domain', 'modules'],
        ]);

        $this->assertSame(['app', 'domain', 'modules'], $config->getWatchPaths());
    }

    public function test_provision_test_db_defaults_to_false()
    {
        $config = ConfigData::from([]);

        $this->assertFalse($config->getProvisionTestDb());
    }

    public function test_provision_test_db_can_be_enabled_via_blueprint()
    {
        $config = ConfigData::from(['provisionTestDb' => true]);

        $this->assertTrue($config->getProvisionTestDb());
    }
}
