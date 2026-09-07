#!/bin/sh
set -eu

# 生成公开 Docker 精简部署包。
#
# 该脚本只从当前私有源码树或导出的公开 backend 树读取已经审查过的部署入口。输出只包含运行配置、
# 初始化脚本和空持久目录，不重复打包 Git 标签已经提供的公开源码；镜像必须使用 CI 返回的 sha256
# digest，防止同名标签漂移。输出目录必须不存在，避免把既有附件、运行数据或密钥混入新版本。

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

# Compose 中的镜像占位值只在这里替换；部署包不携带源码、构建上下文或仓库凭据。
sed "s#ghcr.io/your-org/velin-music:1.0.0#$IMAGE#g" \
    "$BACKEND_ROOT/compose.release.yaml" > "$OUTPUT/compose.yaml"
sed "s#^VELIN_BACKEND_IMAGE=.*#VELIN_BACKEND_IMAGE=$IMAGE#" \
    "$BACKEND_ROOT/.env.docker.example" > "$OUTPUT/.env.docker.example"
sed "s#<IMAGE>#${IMAGE}#g; s#<VERSION>#${VERSION}#g" \
    "$BACKEND_ROOT/docker/deployment/README.md" > "$OUTPUT/README.md"
cp -- "$BACKEND_ROOT/bin/docker-bootstrap" "$OUTPUT/bin/docker-bootstrap"
cp -- "$BACKEND_ROOT/docker/owntone/owntone.conf" "$OUTPUT/docker/owntone/owntone.conf"
cp -- "$DATABASE_GITIGNORE" "$OUTPUT/docker-data/database/.gitignore"

# 占位文件只保证两个 bind source 在解压后存在；容器首次启动负责建立受管子目录和生产密钥。
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

# 清单覆盖隐藏配置模板和所有空目录占位文件；清单本身不包含在自身摘要中。
(
    cd "$OUTPUT"
    find . -type f -not -path './CONTENTS.sha256' -print0 | sort -z | xargs -0 sha256sum > CONTENTS.sha256
)
chmod 0644 "$OUTPUT/CONTENTS.sha256"

printf 'Deployment package %s created at %s\n' "$VERSION" "$OUTPUT"
printf 'Image: %s\n' "$IMAGE"
