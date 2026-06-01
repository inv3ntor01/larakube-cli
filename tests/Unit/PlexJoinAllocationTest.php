<?php

/**
 * Pure-logic tests for the tenant-join allocation helpers — identifier
 * sanitization, Redis-index allocation, .env merging, the connection values,
 * the idempotent Postgres SQL, and registry transforms. The kubectl/psql I/O in
 * PlexJoinCommand belongs in a cluster smoke test, not here.
 */

use App\Traits\InteractsWithPlex;

function plexJoin(): object
{
    return new class
    {
        use InteractsWithPlex;
    };
}

test('tenant identifier sanitizes app names to safe SQL identifiers', function () {
    $p = plexJoin();

    expect($p->plexTenantIdentifier('app-one'))->toBe('app_one')
        ->and($p->plexTenantIdentifier('My App!'))->toBe('my_app')
        ->and($p->plexTenantIdentifier('  Drift.Labs  '))->toBe('drift_labs')
        ->and($p->plexTenantIdentifier('1app'))->toBe('app_1app')   // must start with a letter
        ->and($p->plexTenantIdentifier(''))->toBe('app_');
});

test('redis index allocation picks the lowest free slot, or null when full', function () {
    $p = plexJoin();

    expect($p->allocateRedisDbIndex([]))->toBe(0)
        ->and($p->allocateRedisDbIndex([0, 1, 2]))->toBe(3)
        ->and($p->allocateRedisDbIndex([0, 2]))->toBe(1)            // fills the gap
        ->and($p->allocateRedisDbIndex(range(0, 15)))->toBeNull();  // 16 logical DBs, full
});

test('applyEnvValues replaces in place (even commented) and appends new keys', function () {
    $p = plexJoin();

    $content = "APP_NAME=Demo\n# DB_HOST=old\nDB_PASSWORD=keepme";
    $out = $p->applyEnvValues($content, ['DB_HOST' => 'postgres.larakube-shared.svc.cluster.local', 'REDIS_DB' => 3]);

    expect($out)
        ->toContain('APP_NAME=Demo')                                          // untouched
        ->toContain('DB_HOST=postgres.larakube-shared.svc.cluster.local')     // uncommented + replaced
        ->not->toContain('# DB_HOST=old')
        ->toContain('REDIS_DB=3')                                             // appended
        ->toContain('DB_PASSWORD=keepme');
});

test('commonsEnvValues emits only the requested services', function () {
    $p = plexJoin();

    $both = $p->commonsEnvValues('app_one', 'secret', 5, ['postgres', 'redis']);
    expect($both['DB_HOST'])->toBe('postgres.larakube-shared.svc.cluster.local')
        ->and($both['DB_DATABASE'])->toBe('app_one')
        ->and($both['DB_USERNAME'])->toBe('app_one')
        ->and($both['DB_PASSWORD'])->toBe('secret')
        ->and($both['REDIS_HOST'])->toBe('redis.larakube-shared.svc.cluster.local')
        ->and($both['REDIS_DB'])->toBe(5);

    $redisOnly = $p->commonsEnvValues('app_one', 'secret', 2, ['redis']);
    expect($redisOnly)->not->toHaveKey('DB_HOST')
        ->and($redisOnly['REDIS_DB'])->toBe(2);
});

test('postgres tenant SQL is idempotent and escapes the password', function () {
    $p = plexJoin();

    $sql = $p->buildPostgresTenantSql('app_one', 'app_one', "pa'ss");

    expect($sql)
        ->toContain("WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'app_one')")  // create-db guard
        ->toContain('IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = \'app_one\')')      // create-role guard
        ->toContain('GRANT ALL PRIVILEGES ON DATABASE "app_one" TO "app_one"')
        ->toContain("PASSWORD 'pa''ss'");                                                    // '' escaping
});

test('registry transforms add, remove, and report used redis indexes', function () {
    $p = plexJoin();

    $r = $p->registryAdd([], 'app_one', ['db' => 'app_one', 'redis_index' => 0]);
    $r = $p->registryAdd($r, 'app_two', ['db' => 'app_two', 'redis_index' => 1]);

    expect($p->registryUsedRedisIndexes($r))->toBe([0, 1]);

    $r = $p->registryRemove($r, 'app_one');
    expect($r['tenants'])->toHaveKey('app_two')
        ->and($r['tenants'])->not->toHaveKey('app_one')
        ->and($p->registryUsedRedisIndexes($r))->toBe([1]);
});
