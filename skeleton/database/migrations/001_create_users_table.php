<?php

declare(strict_types=1);

use Phpvin\Database\Connection;

return function (Connection $db): void {
    $autoIncrement = $db->driver() === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';

    $db->statement(
        "CREATE TABLE users (
            id $autoIncrement,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at VARCHAR(32) NULL,
            updated_at VARCHAR(32) NULL
        )"
    );

    $db->statement('CREATE UNIQUE INDEX users_email_unique ON users (email)');
};
