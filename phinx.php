<?php

declare(strict_types=1);

/**
 * 为裸机与 Docker 选择同一业务库以及各自不可被运行数据遮蔽的迁移源码目录。
 *
 * `VELIN_DB_PATH` 与 Webman 使用相同输入，防止迁移一个 SQLite 文件而 Worker 读取另一个文件。Docker
 * 的 `/app/database` 是纯运行数据挂载，因此迁移随镜像发布到 `/opt/velin/migrations`；裸机仍从版本库中的
 * `database/migrations` 读取。容器标记由镜像构建固定创建，部署者不能通过 `.env` 切换迁移来源。
 * 路径缺失时 Phinx 必须在任何 schema 写入前失败，不回退或复制宿主迁移文件。
 */

$databasePath = getenv('VELIN_DB_PATH') ?: __DIR__ . '/database/velin.sqlite';
$migrationPath = is_file(__DIR__ . '/.velin-container')
    ? '/opt/velin/migrations'
    : __DIR__ . '/database/migrations';

return [
    'paths' => [
        'migrations' => $migrationPath,
        'seeds' => __DIR__ . '/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'sqlite',
            'name' => $databasePath,
            // 禁止 Phinx 自动追加 `.sqlite3`，否则迁移会与 Webman 读取的精确文件路径分叉。
            'suffix' => '',
        ],
        'production' => [
            'adapter' => 'sqlite',
            'name' => $databasePath,
            'suffix' => '',
        ],
    ],
    'version_order' => 'creation',
];
