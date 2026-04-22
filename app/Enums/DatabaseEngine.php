<?php

namespace App\Enums;

use App\Actions\Contracts\DatabaseAction;
use App\Actions\Databases\MariaDbAction;
use App\Actions\Databases\MongoDbAction;
use App\Actions\Databases\MySqlAction;
use App\Actions\Databases\PostgresAction;
use App\Actions\Databases\RedisAction;

enum DatabaseEngine: string
{
    case MYSQL = 'MySQL';
    case MARIADB = 'MariaDB';
    case POSTGRESQL = 'PostgreSQL';
    case MONGODB = 'MongoDB';
    case REDIS = 'Redis';

    public function action(): ?DatabaseAction
    {
        return match ($this) {
            self::MYSQL => new MySqlAction,
            self::MARIADB => new MariaDbAction,
            self::POSTGRESQL => new PostgresAction,
            self::MONGODB => new MongoDbAction,
            self::REDIS => new RedisAction,
        };
    }

    public function dbConnection(): string
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 'mysql',
            self::POSTGRESQL => 'pgsql',
            self::MONGODB => 'mongodb',
        };
    }

    public function dbHost(): string
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 'mysql',
            self::POSTGRESQL => 'postgres',
            self::MONGODB => 'mongodb',
            self::REDIS => 'redis',
        };
    }

    public function dbPort(): int
    {
        return match ($this) {
            self::POSTGRESQL => 5432,
            self::MONGODB => 27017,
            self::REDIS => 6379,
            default => 3306,
        };
    }

    public function dbUsername(): string
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 'laravel',
            self::POSTGRESQL => 'postgres',
            self::MONGODB => 'root',
            self::REDIS => 'root',
        };
    }

    public function isPersistent(): bool
    {
        return $this !== self::REDIS;
    }
}
