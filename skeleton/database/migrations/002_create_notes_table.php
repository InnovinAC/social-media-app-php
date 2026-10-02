<?php

declare(strict_types=1);

use Phpvin\Database\Connection;

return function (Connection $db): void {
    $autoIncrement = $db->driver() === 'sqlite'
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';

    $db->statement(
        "CREATE TABLE notes (
            id $autoIncrement,
            user_id INT NOT NULL,
            body VARCHAR(200) NOT NULL,
            created_at VARCHAR(32) NULL,
            updated_at VARCHAR(32) NULL
        )"
    );

    $db->statement('CREATE INDEX notes_user_id_index ON notes (user_id)');
};
