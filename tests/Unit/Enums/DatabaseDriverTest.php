<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;

test('database driver has correct labels', function () {
    expect(DatabaseDriver::MYSQL->getLabel())->toBe('MySQL')
        ->and(DatabaseDriver::MARIADB->getLabel())->toBe('MariaDB')
        ->and(DatabaseDriver::POSTGRESQL->getLabel())->toBe('PostgreSQL')
        ->and(DatabaseDriver::MONGODB->getLabel())->toBe('MongoDB')
        ->and(DatabaseDriver::SQLITE->getLabel())->toBe('SQLite (Local File)');
});

test('database driver has correct ports', function () {
    expect(DatabaseDriver::MYSQL->dbPort())->toBe(3306)
        ->and(DatabaseDriver::MARIADB->dbPort())->toBe(3306)
        ->and(DatabaseDriver::POSTGRESQL->dbPort())->toBe(5432)
        ->and(DatabaseDriver::MONGODB->dbPort())->toBe(27017)
        ->and(DatabaseDriver::SQLITE->dbPort())->toBe(0);
});

test('database driver has correct connections', function () {
    expect(DatabaseDriver::MYSQL->dbConnection())->toBe('mysql')
        ->and(DatabaseDriver::POSTGRESQL->dbConnection())->toBe('pgsql')
        ->and(DatabaseDriver::MONGODB->dbConnection())->toBe('mongodb')
        ->and(DatabaseDriver::SQLITE->dbConnection())->toBe('sqlite');
});

test('sqlite is hidden when using frankenphp', function () {
    $config = ConfigData::from(['serverVariation' => 'frankenphp']);
    expect(DatabaseDriver::SQLITE->isHidden($config))->toBeTrue();

    $config = ConfigData::from(['serverVariation' => 'fpm-nginx']);
    expect(DatabaseDriver::SQLITE->isHidden($config))->toBeFalse();
});

test('database driver select options are valid', function () {
    $options = DatabaseDriver::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('mysql', 'MySQL');
});

test('test database provision command is null for drivers without auto-provisioning', function () {
    expect(DatabaseDriver::SQLITE->getTestDatabaseProvisionCommand('app_testing'))->toBeNull()
        ->and(DatabaseDriver::MONGODB->getTestDatabaseProvisionCommand('app_testing'))->toBeNull();
});

test('mysql test database provision command uses CREATE DATABASE IF NOT EXISTS', function () {
    $cmd = DatabaseDriver::MYSQL->getTestDatabaseProvisionCommand('demo_testing');

    expect($cmd)->toContain('CREATE DATABASE IF NOT EXISTS')
        ->and($cmd)->toContain('`demo_testing`')
        ->and($cmd)->toContain('$MYSQL_ROOT_PASSWORD');
});

test('mariadb uses the same provisioning command as mysql', function () {
    $mariadbCmd = DatabaseDriver::MARIADB->getTestDatabaseProvisionCommand('app_testing');
    $mysqlCmd = DatabaseDriver::MYSQL->getTestDatabaseProvisionCommand('app_testing');

    expect($mariadbCmd)->toBe($mysqlCmd);
});

test('postgres test database provision command checks pg_database before createdb', function () {
    $cmd = DatabaseDriver::POSTGRESQL->getTestDatabaseProvisionCommand('demo_testing');

    expect($cmd)->toContain("SELECT 1 FROM pg_database WHERE datname='demo_testing'")
        ->and($cmd)->toContain('createdb -U "$POSTGRES_USER" "demo_testing"')
        ->and($cmd)->toContain('PGPASSWORD="$POSTGRES_PASSWORD"')
        ->and($cmd)->toContain('-d "$POSTGRES_DB"')
        ->and($cmd)->toContain('||');
});
