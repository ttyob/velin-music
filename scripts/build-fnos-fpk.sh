#!/bin/sh
set -eu

# 生成飞牛 fnOS Docker 型 FPK。
#
# FPK 只封装不可变镜像引用、Compose、桌面入口和生命周期元数据，不复制源码、Docker 镜像层或运行数据。
# 输出路径必须不存在，镜像必须固定到 sha256 digest；任一步失败只删除 mktemp 工作区，不触碰现有包。

usage()
{
    printf '%s\n' "Usage: $0 <image@sha256:digest> <version> <output.fpk>" >&2
    exit 2
}

[ "$#" -eq 3 ] || usage
IMAGE=$1
VERSION=$2
OUTPUT=$3
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
TEMPLATE_ROOT="$PROJECT_ROOT/packaging/fnos"

printf '%s\n' "$IMAGE" | grep -Eq '^[a-z0-9][a-z0-9._/-]*(:[0-9]+)?/[a-z0-9][a-z0-9._/-]*@sha256:[a-f0-9]{64}$' || {
    printf '%s\n' 'FPK image must be an immutable registry sha256 digest.' >&2
    exit 1
}
printf '%s\n' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || {
    printf '%s\n' 'FPK version must use stable SemVer MAJOR.MINOR.PATCH.' >&2
    exit 1
}
case "$OUTPUT" in
    *.fpk) ;;
    *) printf '%s\n' 'Output filename must end in .fpk.' >&2; exit 1 ;;
esac
[ ! -e "$OUTPUT" ] || {
    printf '%s\n' "Refusing to overwrite existing FPK: $OUTPUT" >&2
    exit 1
}
[ ! -e "$OUTPUT.sha256" ] || {
    printf '%s\n' "Refusing to overwrite existing checksum: $OUTPUT.sha256" >&2
    exit 1
}

for command_name in docker tar gzip md5sum sha256sum sed od php; do
    command -v "$command_name" >/dev/null 2>&1 || {
        printf '%s\n' "Required build command is missing: $command_name" >&2
        exit 1
    }
done

# fnOS 不会替第三方图标裁掉方形画布，圆角底板外的四角必须由图片自身提供透明 Alpha，否则边框外仍会
# 出现尖角。这里固定为 8-bit RGBA，并逐像素确认四角透明、中心底板不透明；失败时在写包前退出，不会
# 留下一个只能靠浏览器缓存参数掩盖外观问题的 FPK。
validate_fpk_icon()
{
    icon_path=$1
    expected_size_hex=$2
    expected_size=$3
    rounding_probe_x=$4
    rounding_probe_y=$5
    icon_signature=$(od -An -v -tx1 -N8 "$icon_path" | tr -d ' \n')
    icon_size=$(od -An -v -tx1 -j16 -N8 "$icon_path" | tr -d ' \n')
    icon_encoding=$(od -An -v -tx1 -j24 -N2 "$icon_path" | tr -d ' \n')

    [ "$icon_signature" = 89504e470d0a1a0a ] \
        && [ "$icon_size" = "${expected_size_hex}${expected_size_hex}" ] \
        && [ "$icon_encoding" = 0806 ] \
        && ! LC_ALL=C grep -aF 'tRNS' "$icon_path" >/dev/null 2>&1 || {
        printf '%s\n' "FPK icon must be an 8-bit RGBA PNG with the expected dimensions: $icon_path" >&2
        exit 1
    }

    php -r '
        $image = @imagecreatefrompng($argv[1]);
        $size = (int) $argv[2];
        if ($image === false || imagesx($image) !== $size || imagesy($image) !== $size) {
            exit(1);
        }
        foreach ([[0, 0], [$size - 1, 0], [0, $size - 1], [$size - 1, $size - 1]] as [$x, $y]) {
            if (((imagecolorat($image, $x, $y) >> 24) & 0x7f) !== 127) {
                exit(1);
            }
        }
        if (((imagecolorat($image, intdiv($size, 2), intdiv($size, 2)) >> 24) & 0x7f) !== 0) {
            exit(1);
        }
        $probeAlpha = (imagecolorat($image, (int) $argv[3], (int) $argv[4]) >> 24) & 0x7f;
        if ($probeAlpha < 16) {
            exit(1);
        }
    ' "$icon_path" "$expected_size" "$rounding_probe_x" "$rounding_probe_y" || {
        printf '%s\n' "FPK icon corners and rounding probe must be transparent while its center remains opaque: $icon_path" >&2
        exit 1
    }
}

# FPK 不携带镜像层，因此必须在出包前验证远端 digest 真正对应当前单容器运行合同。只检查只读文件与
# 可执行文件，不启动应用入口、不挂载宿主目录且禁用网络；旧三容器版本会在这里失败，不能生成伪可用包。
docker image inspect "$IMAGE" >/dev/null 2>&1 || docker pull "$IMAGE" >/dev/null
image_architecture=$(docker image inspect --format '{{.Architecture}}' "$IMAGE")
[ "$image_architecture" = amd64 ] || {
    printf '%s\n' "FPK image architecture must be amd64, got: $image_architecture" >&2
    exit 1
}
image_version=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' "$IMAGE")
[ "$image_version" = "$VERSION" ] || {
    printf '%s\n' "FPK version must match image OCI version label: expected $VERSION, got ${image_version:-missing}" >&2
    exit 1
}
docker run --rm --network none --entrypoint sh "$IMAGE" -c '
    set -eu
    test -f /app/.velin-container
    test -x /usr/bin/redis-server
    test -x /usr/bin/redis-cli
    test -x /usr/sbin/owntone
    test -x /usr/sbin/avahi-daemon
    test -x /usr/bin/dbus-daemon
    test -x /app/bin/velin-redis-companion
    test -x /app/bin/velin-airplay-companion
    test -x /app/bin/velin-media-gateway
' || {
    printf '%s\n' 'FPK image does not satisfy the embedded Redis/AirPlay single-container contract.' >&2
    exit 1
}
for required_path in \
    "$TEMPLATE_ROOT/manifest.template" \
    "$TEMPLATE_ROOT/config/resource" \
    "$TEMPLATE_ROOT/config/privilege" \
    "$TEMPLATE_ROOT/wizard/install" \
    "$TEMPLATE_ROOT/wizard/uninstall" \
    "$TEMPLATE_ROOT/cmd/uninstall-storage" \
    "$TEMPLATE_ROOT/app/docker/docker-compose.yaml.template" \
    "$TEMPLATE_ROOT/app/ui/config" \
    "$TEMPLATE_ROOT/assets/icon-256.png" \
    "$TEMPLATE_ROOT/assets/icon-64.png"; do
    [ -e "$required_path" ] || {
        printf '%s\n' "Required FPK source is missing: $required_path" >&2
        exit 1
    }
done

validate_fpk_icon "$TEMPLATE_ROOT/assets/icon-256.png" 00000100 256 20 8
validate_fpk_icon "$TEMPLATE_ROOT/assets/icon-64.png" 00000040 64 5 2

output_parent=$(dirname -- "$OUTPUT")
[ -d "$output_parent" ] || {
    printf '%s\n' "Output directory does not exist: $output_parent" >&2
    exit 1
}
output_name=$(basename -- "$OUTPUT")
output_parent=$(CDPATH= cd -- "$output_parent" && pwd)
OUTPUT="$output_parent/$output_name"

WORK_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/velin-fnos-fpk.XXXXXX")
trap 'rm -rf -- "$WORK_ROOT"' EXIT HUP INT TERM
APP_ROOT="$WORK_ROOT/app"
PACKAGE_ROOT="$WORK_ROOT/package"
mkdir -p "$APP_ROOT/docker" "$APP_ROOT/ui/images" "$PACKAGE_ROOT"

# 只替换构建器拥有的镜像与图标缓存版本占位符；TRIM_PKGVAR 与 wizard_* 必须原样留给 fnOS 安装环境解析。
sed "s#@IMAGE@#$IMAGE#g" \
    "$TEMPLATE_ROOT/app/docker/docker-compose.yaml.template" > "$APP_ROOT/docker/docker-compose.yaml"
sed "s#@VERSION@#$VERSION#g" \
    "$TEMPLATE_ROOT/app/ui/config" > "$APP_ROOT/ui/config"
cp -- "$TEMPLATE_ROOT/assets/icon-256.png" "$APP_ROOT/ui/images/256.png"
cp -- "$TEMPLATE_ROOT/assets/icon-64.png" "$APP_ROOT/ui/images/64.png"

# 图标 URL 必须随 FPK 版本变化；未替换占位符或缺少版本查询参数都会让 fnOS 继续复用旧图标缓存。
grep -Fx "      \"icon\": \"images/{0}.png?v=$VERSION\"," "$APP_ROOT/ui/config" >/dev/null || {
    printf '%s\n' 'FPK desktop icon cache version was not rendered correctly.' >&2
    exit 1
}

(cd "$APP_ROOT" && tar -czf "$WORK_ROOT/app.tgz" docker ui)
app_checksum=$(md5sum "$WORK_ROOT/app.tgz" | awk '{print $1}')

cp -- "$WORK_ROOT/app.tgz" "$PACKAGE_ROOT/app.tgz"
cp -a -- "$TEMPLATE_ROOT/cmd" "$TEMPLATE_ROOT/config" "$TEMPLATE_ROOT/wizard" "$PACKAGE_ROOT/"
cp -a -- "$APP_ROOT/ui" "$PACKAGE_ROOT/ui"
cp -- "$TEMPLATE_ROOT/VelinMusic.sc" "$PACKAGE_ROOT/VelinMusic.sc"
cp -- "$TEMPLATE_ROOT/assets/icon-256.png" "$PACKAGE_ROOT/ICON_256.PNG"
cp -- "$TEMPLATE_ROOT/assets/icon-64.png" "$PACKAGE_ROOT/ICON.PNG"
sed "s#@VERSION@#$VERSION#g; s#@CHECKSUM@#$app_checksum#g" \
    "$TEMPLATE_ROOT/manifest.template" > "$PACKAGE_ROOT/manifest"
chmod 0755 "$PACKAGE_ROOT/cmd/"*
chmod 0644 "$PACKAGE_ROOT/app.tgz" "$PACKAGE_ROOT/manifest" "$PACKAGE_ROOT/VelinMusic.sc" \
    "$PACKAGE_ROOT/ICON.PNG" "$PACKAGE_ROOT/ICON_256.PNG" "$PACKAGE_ROOT/config/"* \
    "$PACKAGE_ROOT/ui/config" "$PACKAGE_ROOT/ui/images/"* "$PACKAGE_ROOT/wizard/"*

# 外层使用 fnOS 可直接读取的 gzip tar；构建前已固定权限，包内不会出现工作机 UID/GID 或隐藏密钥文件。
(cd "$PACKAGE_ROOT" && tar --owner=0 --group=0 --numeric-owner -czf "$OUTPUT" \
    app.tgz cmd config ICON.PNG ICON_256.PNG manifest ui VelinMusic.sc wizard)
(cd "$output_parent" && sha256sum "$output_name" > "$output_name.sha256")

tar -tzf "$OUTPUT" | grep -Fx 'app.tgz' >/dev/null
tar -tzf "$OUTPUT" | grep -Fx 'manifest' >/dev/null
tar -tzf "$OUTPUT" | grep -Fx 'config/resource' >/dev/null
tar -tzf "$OUTPUT" | grep -Fx 'cmd/main' >/dev/null

printf 'fnOS FPK %s created at %s\n' "$VERSION" "$OUTPUT"
printf 'Image: %s\n' "$IMAGE"
printf 'SHA-256: %s\n' "$(awk '{print $1}' "$OUTPUT.sha256")"
