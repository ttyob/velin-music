#!/bin/sh
set -eu

# backend 是唯一应用容器。只有部署配置不存在、密钥缺失/为空、仍是示例占位值或重复定义时才进入
# compose 初始化模式；已有三项独立有效密钥直接进入启动流程，避免每次重启都重复检查和准备宿主目录。
# 初始化脚本仍负责原子生成密钥、清理旧配置键和准备固定媒体布局；任何失败都会阻止配置复制、数据库
# 迁移以及 HTTP/Worker 启动，避免把半初始化状态带入 Webman。
DEPLOYMENT_ENV=/data/config/.env
deployment_initialized=false
if [ -f "$DEPLOYMENT_ENV" ] && [ -r "$DEPLOYMENT_ENV" ] && [ ! -L "$DEPLOYMENT_ENV" ]; then
    auth_count=$(grep -c '^VELIN_AUTH_HASH_KEY=' "$DEPLOYMENT_ENV" || true)
    credential_count=$(grep -c '^VELIN_CREDENTIAL_KEY=' "$DEPLOYMENT_ENV" || true)
    metrics_count=$(grep -c '^VELIN_METRICS_TOKEN=' "$DEPLOYMENT_ENV" || true)
    obsolete_count=$(grep -Ec '^(VELIN_WEB_HOST_PORT|VELIN_REDIS_HOST_PORT|VELIN_MEDIA_HOST_PATH|VELIN_SCRAPE_CACHE_HOST_PATH|VELIN_ALPINE_REPOSITORY|VELIN_FFPROBE_DOWNLOAD_PREFIX|VELIN_GO_PROXY|VELIN_SESSION_DRIVER|VELIN_REDIS_HOST|VELIN_REDIS_PORT|VELIN_REDIS_DATABASE)=' "$DEPLOYMENT_ENV" || true)
    if [ "$auth_count" = 1 ] && [ "$credential_count" = 1 ] && [ "$metrics_count" = 1 ] \
        && [ "$obsolete_count" = 0 ]; then
        auth_value=$(sed -n 's/^VELIN_AUTH_HASH_KEY=//p' "$DEPLOYMENT_ENV")
        credential_value=$(sed -n 's/^VELIN_CREDENTIAL_KEY=//p' "$DEPLOYMENT_ENV")
        metrics_value=$(sed -n 's/^VELIN_METRICS_TOKEN=//p' "$DEPLOYMENT_ENV")
        case "$auth_value:$credential_value:$metrics_value" in
            :*|*::*|*:|*replace-with-a-long-random-production-secret*|*replace-with-an-independent-long-random-production-secret*|*replace-with-an-independent-random-metrics-token*) ;;
            *) deployment_initialized=true ;;
        esac
    fi
fi
if [ "$deployment_initialized" = false ]; then
    /app/bin/docker-bootstrap --compose-init "$DEPLOYMENT_ENV"
fi

# 初始化完成后只复制配置快照到容器文件系统，Webman Dotenv 不直接读取 `/data`。配置目录首次启动
# 需要可写以原子生成密钥；业务运行阶段只读取 `/app/.env` 快照，不把密钥放入 Compose environment。
[ ! -L "$DEPLOYMENT_ENV" ] || {
    printf '%s\n' "Refusing symlinked deployment environment file: $DEPLOYMENT_ENV" >&2
    exit 1
}
[ -f "$DEPLOYMENT_ENV" ] && [ -r "$DEPLOYMENT_ENV" ] || {
    printf '%s\n' "Deployment environment file is missing or unreadable: $DEPLOYMENT_ENV" >&2
    exit 1
}
cp -- "$DEPLOYMENT_ENV" /app/.env
chmod 0400 /app/.env

# Redis 是 backend 镜像的必需内部进程，持久文件直接位于统一 `/data/redis` 运行挂载，不再声明独立
# Docker volume。旧命名卷只在维护窗口由宿主迁移一次；启动失败必须发生在迁移和任何业务 Worker 之前。
# 初始化阶段若后续步骤失败，EXIT trap 会以 SIGTERM 停止 Redis 并等待刷盘；成功 exec Workerman 前移除
# trap，运行期由单实例 RedisCompanionWorker 负责恢复和优雅停止。
test -x /app/bin/velin-redis-companion
test -x /usr/bin/redis-server
test -x /usr/bin/redis-cli
/app/bin/velin-redis-companion start
cleanup_embedded_redis()
{
    /app/bin/velin-redis-companion stop || true
}
trap cleanup_embedded_redis EXIT
trap 'exit 143' HUP INT TERM

# Container startup is the only automatic migration boundary. Migrations modify SQLite, while media
# directory provisioning remains owned by the dedicated scan Worker after Workerman starts. A failed
# migration stops the container before any HTTP worker can serve an incompatible schema.
if [ "${VELIN_AUTO_MIGRATE:-true}" = "true" ]; then
    php vendor/bin/phinx migrate -c phinx.php -e production
fi

# 镜像默认插件不是构建期活动目录：核心 schema 迁移成功后，启动命令才按只读清单把尚未初始化的包交给
# 通用安装器。一次性标记保存在 `/data/plugins/.initial-plugins`，所以重启不会覆盖已有包、配置、启停状态，
# 管理员后续卸载也不会被自动撤销。初始化失败时必须先于全部 Webman/插件 Worker 停止启动。
php bin/velin plugin:initialize-defaults --json

# 后台卸载 PHP 插件采用两阶段协议：HTTP 请求只移除活动标记，防止仍在运行的旧 Worker 访问已删表；
# 容器重启后在任何 Webman/插件 Worker 创建前完成数据库删除和包目录清理。失败会停止启动并保留待处理
# 包以供下次重试，绝不能带着半卸载 schema 继续提供请求。
php bin/velin plugin:finalize-pending --json

# Fail early when the image no longer satisfies the explicit runtime contract. No user-controlled
# values enter these commands, and the checks perform no writes beyond migrations above.
php -r '
    $required = ["pcntl", "pdo_sqlite", "posix", "redis", "sockets", "zip"];
    foreach ($required as $extension) {
        if (!extension_loaded($extension)) {
            fwrite(STDERR, "Missing PHP extension: {$extension}\n");
            exit(1);
        }
    }
    if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
        fwrite(STDERR, "Velin Music container requires PHP 8.3.x.\n");
        exit(1);
    }
    if (trim((string) ini_get("disable_functions")) !== "") {
        fwrite(STDERR, "PHP disable_functions must be empty in this image.\n");
        exit(1);
    }
'

/app/bin/ffprobe -version >/dev/null 2>&1
/app/bin/velin-library-watch-helper --version >/dev/null 2>&1
test "$(/app/bin/velin-media-gateway --version)" = 'velin-media-gateway protocol=1 static=1'
/app/bin/fpcalc -version >/dev/null 2>&1
test -x /app/bin/opencc
test -r /app/bin/opencc-data/t2s.json
test "$(printf '周杰倫 - 稻香 Live現場版\n' | /app/bin/opencc -c /app/bin/opencc-data/t2s.json)" = '周杰伦 - 稻香 Live现场版'
test -r /app/bin/opencc-data/tw2s.json
test "$(printf '活著多好 - 陳奕迅\n' | /app/bin/opencc -c /app/bin/opencc-data/tw2s.json)" = '活着多好 - 陈奕迅'
test -r /app/bin/webdav-range-proxy.php
php -l /app/bin/webdav-range-proxy.php >/dev/null 2>&1
test -x /app/bin/velin-airplay-companion
test -x /usr/sbin/owntone
test -x /usr/sbin/avahi-daemon
test -x /usr/bin/dbus-daemon
test -r /etc/owntone/owntone.conf
test -r /usr/share/licenses/owntone/COPYING
test "$(redis-cli -h 127.0.0.1 -p 27379 ping)" = PONG

# DLNA 默认关闭以避免闲置 helper 占用内存。管理员在后台开启后，系统设置接口动态启动唯一常驻 daemon；
# 关闭设置时发送停止信号并清理 Socket，已写入 Socket 的命令不得重试。

# AirPlay 同样默认关闭。单实例 Workerman 生命周期进程根据数据库设置启动或停止镜像内 OwnTone、Avahi
# 与 D-Bus；入口只验证交付物，不预启动服务，也不要求 3689 在 backend 健康检查期间监听。

# Workerman 的固定 Redis 生命周期 Worker 接管运行期恢复和退出刷盘后，入口移除初始化失败补偿 trap。
# exec 让 Docker init 直接转发信号给 Workerman，以完成租约释放、SQLite checkpoint 和 Redis SIGTERM。
trap - EXIT HUP INT TERM
exec "$@"
