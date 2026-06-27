<?php

use App\Data\ConfigData;
use App\Enums\CompanionDriver;
use App\Traits\ManagesCompanions;

function companionsHarness(array $preInstalled = []): object
{
    return new class($preInstalled)
    {
        use ManagesCompanions;

        public array $deployed = [];

        public bool $namespaceEnsured = false;

        public bool $phpMyAdminRefreshed = false;

        public function __construct(private array $preInstalled) {}

        public function ensure(ConfigData $config, string $appName): void
        {
            $this->ensureProjectCompanions($config, $appName);
        }

        protected function isCompanionInstalled(CompanionDriver $companion): bool
        {
            return in_array($companion, $this->preInstalled, true);
        }

        protected function ensureCompanionNamespace(): void
        {
            $this->namespaceEnsured = true;
        }

        protected function deployCompanion(CompanionDriver $companion): void
        {
            $this->deployed[] = $companion;
        }

        protected function refreshPhpMyAdminServers(ConfigData $config, string $appName): void
        {
            $this->phpMyAdminRefreshed = true;
        }
    };
}

test('ensureProjectCompanions does nothing when withCompanions is false', function () {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mariadb', 'withCompanions' => false]);
    $harness = companionsHarness();

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([])
        ->and($harness->namespaceEnsured)->toBeFalse()
        ->and($harness->phpMyAdminRefreshed)->toBeFalse();
});

test('ensureProjectCompanions deploys phpMyAdmin for MariaDB and refreshes its server list', function () {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mariadb']);
    $harness = companionsHarness();

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([CompanionDriver::PHPMYADMIN])
        ->and($harness->namespaceEnsured)->toBeTrue()
        ->and($harness->phpMyAdminRefreshed)->toBeTrue();
});

test('ensureProjectCompanions deploys pgAdmin for PostgreSQL', function () {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'postgres']);
    $harness = companionsHarness();

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([CompanionDriver::PGADMIN]);
});

test('ensureProjectCompanions deploys Mongo Express for MongoDB', function () {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mongodb']);
    $harness = companionsHarness();

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([CompanionDriver::MONGO_EXPRESS]);
});

test('ensureProjectCompanions deploys nothing for SQLite', function () {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'sqlite']);
    $harness = companionsHarness();

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([]);
});

test('ensureProjectCompanions adds RedisInsight alongside the database companion', function () {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mariadb', 'cacheDriver' => 'redis']);
    $harness = companionsHarness();

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toContain(CompanionDriver::PHPMYADMIN)
        ->and($harness->deployed)->toContain(CompanionDriver::REDISINSIGHT);
});

test('ensureProjectCompanions skips memcached — it has no companion at all', function () {
    $config = ConfigData::from(['name' => 'demo', 'cacheDriver' => 'memcached']);
    $harness = companionsHarness();

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([]);
});

test('ensureProjectCompanions re-applies an already-installed companion so its ingress tracks the current TLD', function () {
    // Re-applying unconditionally is the fix for stale companion ingresses after
    // config:tld — a companion installed under the old TLD must be re-rendered on
    // up so its host (e.g. phpmyadmin.localhost) resolves, not left write-once.
    $config = ConfigData::from(['name' => 'demo', 'database' => 'postgres']);
    $harness = companionsHarness([CompanionDriver::PGADMIN]);

    $harness->ensure($config, 'demo');

    expect($harness->deployed)->toBe([CompanionDriver::PGADMIN])
        ->and($harness->namespaceEnsured)->toBeTrue();
});
