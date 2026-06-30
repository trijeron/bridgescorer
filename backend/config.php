<?php
/**
 * Database configuration
 * Copy this file to config.local.php and fill in your values,
 * OR set the environment variables listed below.
 */

return [
    'host'     => getenv('DB_HOST')     ?: 'localhost',
    'port'     => (int)(getenv('DB_PORT') ?: 3306),
    'dbname'   => getenv('DB_NAME')     ?: 'bridgescorer',
    'username' => getenv('DB_USER')     ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset'  => 'utf8mb4',
];
