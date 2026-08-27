<?php

declare(strict_types=1);

/**
 * Velin Music database connections.
 *
 * SQLite is intentionally the only enabled connection in the first release. The
 * connection options are applied whenever Illuminate creates or reconnects a PDO
 * connection, so foreign keys and the busy timeout cannot silently disappear after
 * a worker reconnect. MySQL support will be introduced as an explicit data migration,
 * not by changing this default in an already running installation.
 */

// PHPUnit 的专用引导会设置不可用于生产启动的测试标记。即使某个测试随后加载完整 Webman 配置、
// 临时改写或清除了 VELIN_DB_PATH，连接仍只能落在内存数据库，绝不能回退到生产 SQLite 文件。
$databasePath = getenv('VELIN_TESTING') === '1'
    ? ':memory:'
    : (getenv('VELIN_DB_PATH') ?: base_path('database/velin.sqlite'));

return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => (int) (getenv('VELIN_DB_BUSY_TIMEOUT_MS') ?: 5000),
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'pool' => [
                // One checked-out connection per worker keeps SQLite write pressure bounded.
                'max_connections' => 1,
                'min_connections' => 1,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
    ],
];
