#!/bin/sh
set -eu

# 生成公开 Docker 部署包。
#
# 该脚本只能从当前私有源码树读取已经审查过的部署入口：生产 Compose 只引用 CI 构建的镜像，
# 不在输出包中复制 PHP/前端/Go 源码、测试、vendor、数据库、媒体或运行时数据。输出目录必须
# 不存在，避免把已发布包和新版本混合；镜像必须使用 CI 返回的 sha256 digest，防止同名标签漂移。
# 脚本同时支持私有源码根和自动导出的公开 backend 根，两种输入必须生成完全相同的部署布局。

usage()
{
    printf '%s\n' "Usage: $0 <backend-image> <version> <output-directory>" >&2
    exit 2
}

[ "$#" -eq 3 ] || usage
IMAGE=$1
VERSION=$2
OUTPUT=$3
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
if [ -f "$PROJECT_ROOT/backend/compose.release.yaml" ]; then
    BACKEND_ROOT="$PROJECT_ROOT/backend"
    DATABASE_GITIGNORE="$BACKEND_ROOT/docker-data/database/.gitignore"
else
    BACKEND_ROOT="$PROJECT_ROOT"
    DATABASE_GITIGNORE="$BACKEND_ROOT/docker/deployment/database.gitignore"
fi

printf '%s\n' "$IMAGE" | grep -Eq '^[a-z0-9][a-z0-9._/-]*(:[0-9]+)?/[a-z0-9][a-z0-9._/-]*@sha256:[a-f0-9]{64}$' || {
    printf '%s\n' 'Backend image must be an immutable registry sha256 digest.' >&2
    exit 1
}
printf '%s\n' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || {
    printf '%s\n' 'Release version must use stable SemVer MAJOR.MINOR.PATCH.' >&2
    exit 1
}
[ -e "$OUTPUT" ] && {
    printf '%s\n' "Refusing to overwrite existing deployment package: $OUTPUT" >&2
    exit 1
}

[ -f "$BACKEND_ROOT/compose.release.yaml" ] || {
    printf '%s\n' 'Release Compose definition is missing.' >&2
    exit 1
}
[ -x "$BACKEND_ROOT/bin/docker-bootstrap" ] || {
    printf '%s\n' 'Deployment bootstrap script is missing or not executable.' >&2
    exit 1
}
[ -f "$DATABASE_GITIGNORE" ] || {
    printf '%s\n' 'Deployment database ignore policy is missing.' >&2
    exit 1
}

mkdir -p "$OUTPUT/bin" "$OUTPUT/docker/owntone" \
    "$OUTPUT/docker-data/config" "$OUTPUT/docker-data/database" \
    "$OUTPUT/docker-data/runtime" "$OUTPUT/docker-data/plugins" "$OUTPUT/docker-data/cache" \
    "$OUTPUT/storage/music" "$OUTPUT/storage/downloads"

# Compose 中的占位镜像只在这里替换；输出包不会携带构建上下文或私有注册表凭据。
sed "s#ghcr.io/your-org/velin-music:1.0.0#$IMAGE#g" \
    "$BACKEND_ROOT/compose.release.yaml" > "$OUTPUT/compose.yaml"
sed "s#^VELIN_BACKEND_IMAGE=.*#VELIN_BACKEND_IMAGE=$IMAGE#" \
    "$BACKEND_ROOT/.env.docker.example" > "$OUTPUT/.env.docker.example"
sed "s#<IMAGE>#${IMAGE}#g; s#<VERSION>#${VERSION}#g" \
    "$BACKEND_ROOT/docker/deployment/README.md" > "$OUTPUT/README.md"
cp -- "$BACKEND_ROOT/bin/docker-bootstrap" "$OUTPUT/bin/docker-bootstrap"
cp -- "$BACKEND_ROOT/docker/owntone/owntone.conf" "$OUTPUT/docker/owntone/owntone.conf"
cp -- "$DATABASE_GITIGNORE" "$OUTPUT/docker-data/database/.gitignore"

# 这些占位文件只保证数据库 bind source 在全新解压目录中存在；业务数据仍必须由部署者创建或恢复。
: > "$OUTPUT/docker-data/config/.gitkeep"
: > "$OUTPUT/docker-data/runtime/.gitkeep"
: > "$OUTPUT/docker-data/plugins/.gitkeep"
: > "$OUTPUT/docker-data/cache/.gitkeep"
: > "$OUTPUT/storage/music/.gitkeep"
: > "$OUTPUT/storage/downloads/.gitkeep"
chmod 0755 "$OUTPUT/bin/docker-bootstrap"
chmod 0644 "$OUTPUT/compose.yaml" "$OUTPUT/.env.docker.example" "$OUTPUT/README.md" \
    "$OUTPUT/docker/owntone/owntone.conf" "$OUTPUT/docker-data/database/.gitignore" \
    "$OUTPUT/docker-data/config/.gitkeep" "$OUTPUT/docker-data/runtime/.gitkeep" \
    "$OUTPUT/docker-data/plugins/.gitkeep" "$OUTPUT/docker-data/cache/.gitkeep" \
    "$OUTPUT/storage/music/.gitkeep" "$OUTPUT/storage/downloads/.gitkeep"

# 清单覆盖隐藏配置模板和所有空目录占位文件；部署者解压后可先验证清单，再复制生成自己的 `.env`。
# 清单本身不包含在自身摘要中，重复生成同一输入时内容保持稳定。
(
    cd "$OUTPUT"
    find . -type f -not -path './CONTENTS.sha256' -print0 | sort -z | xargs -0 sha256sum > CONTENTS.sha256
)
chmod 0644 "$OUTPUT/CONTENTS.sha256"

printf 'Deployment package %s created at %s\n' "$VERSION" "$OUTPUT"
printf 'Image: %s\n' "$IMAGE"
