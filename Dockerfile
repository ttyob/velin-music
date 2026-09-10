# syntax=docker/dockerfile:1.7

ARG ALPINE_REPOSITORY=https://mirrors.aliyun.com/alpine

# 公开仓库内容由私有 CI 生成清单；镜像构建先校验全部受管文件，再从该只读阶段复制运行内容。
FROM alpine:3.22 AS source-verifier
WORKDIR /source
COPY . .
RUN sha256sum -c CONTENTS.sha256

# 公开构建同样从固定发布包编译 OwnTone，避免官方容器与 backend Alpine 版本不同造成动态库漂移。
FROM alpine:3.22 AS owntone-build
ARG ALPINE_REPOSITORY
ARG OWNTONE_VERSION=29.3
ARG OWNTONE_SHA256=aa0cbfc5651aa65776a8bd2112c9e0a904b2185c837900d3f6494b9ec6e8c547
WORKDIR /tmp/source
RUN set -eux; \
    sed -i "s#https://dl-cdn.alpinelinux.org/alpine#${ALPINE_REPOSITORY}#g" /etc/apk/repositories; \
    apk add --no-cache \
        alsa-lib-dev autoconf automake avahi-dev bison confuse-dev curl curl-dev ffmpeg-dev flex \
        g++ gawk gcc gettext-dev gnutls-dev gperf json-c-dev libevent-dev libgcrypt-dev \
        libplist-dev libsodium-dev libtool libunistring-dev libwebsockets-dev libxml2-dev make \
        protobuf-c-dev sqlite-dev xz; \
    curl --fail --location --retry 5 --retry-all-errors --connect-timeout 20 \
        --output owntone.tar.xz \
        "https://github.com/owntone/owntone-server/releases/download/${OWNTONE_VERSION}/owntone-${OWNTONE_VERSION}.tar.xz"; \
    echo "${OWNTONE_SHA256}  owntone.tar.xz" | sha256sum -c -; \
    tar -xf owntone.tar.xz --strip-components=1; \
    autoreconf -fvi -I /usr/share/gettext/m4; \
    ./configure --disable-install_systemd --disable-install_user --enable-chromecast \
        --enable-silent-rules --infodir=/usr/share/info --localstatedir=/var \
        --mandir=/usr/share/man --prefix=/usr --sysconfdir=/etc/owntone; \
    make -j"$(nproc)"; \
    make DESTDIR=/tmp/build install; \
    install -D -m 0644 COPYING /tmp/build/usr/share/licenses/owntone/COPYING; \
    test -x /tmp/build/usr/sbin/owntone

# 公开仓库只接收私有 CI 生成的 public 静态文件和 amd64 Go Helper；源码不会在镜像构建阶段重新进入。
FROM php:8.3-cli-alpine3.22 AS php-runtime-base

ARG ALPINE_REPOSITORY
RUN set -eux; \
    sed -i "s#https://dl-cdn.alpinelinux.org/alpine#${ALPINE_REPOSITORY}#g" /etc/apk/repositories; \
    apk add --no-cache \
        curl \
        avahi \
        confuse \
        dbus \
        ffmpeg \
        freetype \
        gnutls \
        json-c \
        libevent \
        libgcrypt \
        libjpeg-turbo \
        libplist \
        libpng \
        libsodium \
        libunistring \
        libuuid \
        libwebsockets \
        libwebp \
        libxml2 \
        libzip \
        chromaprint \
        nodejs \
        protobuf-c \
        redis \
        sqlite-libs \
        tzdata

COPY docker/php.ini /usr/local/etc/php/conf.d/99-velin.ini

FROM php-runtime-base AS extension-builder
RUN set -eux; \
    apk add --no-cache --virtual .phpize-deps \
        autoconf dpkg dpkg-dev file gcc libc-dev linux-headers \
        freetype-dev libjpeg-turbo-dev libpng-dev libwebp-dev libzip-dev \
        make pkgconf re2c; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd pcntl sockets zip; \
    pecl install redis-6.1.0; \
    docker-php-ext-enable redis; \
    rm -rf /tmp/pear

FROM php-runtime-base AS app-base
COPY --from=extension-builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extension-builder \
    /usr/local/etc/php/conf.d/docker-php-ext-gd.ini \
    /usr/local/etc/php/conf.d/docker-php-ext-pcntl.ini \
    /usr/local/etc/php/conf.d/docker-php-ext-redis.ini \
    /usr/local/etc/php/conf.d/docker-php-ext-sockets.ini \
    /usr/local/etc/php/conf.d/docker-php-ext-zip.ini \
    /usr/local/etc/php/conf.d/

FROM app-base AS media-runtime-base
COPY --from=source-verifier --chmod=0755 /source/bin/ffmpeg /source/bin/ffprobe /app/bin/
COPY --from=source-verifier --chmod=0644 /source/bin/FFMPEG-LICENSE /app/bin/FFMPEG-LICENSE
RUN set -eux; /app/bin/ffprobe -version >/dev/null; /app/bin/ffmpeg -version >/dev/null
RUN set -eux; \
    mv /usr/bin/fpcalc /app/bin/fpcalc; \
    apk add --no-cache opencc; \
    mv /usr/bin/opencc /app/bin/opencc; \
    mv /usr/share/opencc /app/bin/opencc-data; \
    /app/bin/fpcalc -version >/dev/null; \
    /app/bin/opencc --version >/dev/null 2>&1; \
    test -r /app/bin/opencc-data/t2s.json; \
    test -r /app/bin/opencc-data/tw2s.json; \
    test "$(printf '活著多好 - 陳奕迅\n' | /app/bin/opencc -c /app/bin/opencc-data/tw2s.json)" = '活着多好 - 陈奕迅'

# 默认插件 ZIP 的真实版本由私有导出阶段生成，并由 manifest 作为唯一名称来源。镜像构建只复制该
# 已通过清单校验的归档，不重新解压或重打包，避免 Dockerfile 的固定版本号落后于插件升级；缺失、多个
# 同 key 归档或非法版本都会在构建阶段失败，不能生成带有错误初始化包的镜像。
FROM alpine:3.22 AS initial-plugin-build
WORKDIR /initial-plugins
COPY --from=source-verifier /source/release-assets/plugins /plugins
COPY --from=source-verifier /source/initial-plugins/manifest.json /out/manifest.json
RUN set -eux; \
    archive="$(sed -n 's/^[[:space:]]*"archive"[[:space:]]*:[[:space:]]*"\([^"]*\)"[[:space:]]*$/\1/p' /out/manifest.json)"; \
    printf '%s\n' "$archive" | grep -Eq '^metadata-scrape-(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.zip$'; \
    test -f "/plugins/$archive"; \
    mkdir -p /out; \
    cp -- "/plugins/$archive" "/out/$archive"; \
    test -s "/out/$archive"

FROM media-runtime-base AS vendor
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
COPY --from=source-verifier /source/composer.json /source/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY --from=source-verifier /source/app ./app
COPY --from=source-verifier /source/config ./config
COPY --from=source-verifier /source/public ./public
COPY --from=source-verifier /source/support ./support
COPY --from=source-verifier /source/bin/velin ./bin/velin
COPY --from=source-verifier /source/bin/docker-bootstrap ./bin/docker-bootstrap
COPY --from=source-verifier /source/bin/velin-airplay-companion ./bin/velin-airplay-companion
COPY --from=source-verifier /source/bin/velin-redis-companion ./bin/velin-redis-companion
COPY --from=source-verifier /source/bin/webdav-range-proxy.php ./bin/webdav-range-proxy.php
COPY --from=source-verifier /source/artifacts/bin/linux-amd64/velin-dlna-helper ./bin/velin-dlna-helper
COPY --from=source-verifier /source/artifacts/bin/linux-amd64/velin-library-watch-helper ./bin/velin-library-watch-helper
COPY --from=source-verifier /source/artifacts/bin/linux-amd64/velin-media-gateway ./bin/velin-media-gateway
COPY --from=source-verifier /source/licenses/goupnp.LICENSE ./bin/goupnp.LICENSE
COPY --from=source-verifier /source/.env.docker.example /source/phinx.php /source/start.php ./
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

FROM media-runtime-base AS runtime
ARG VELIN_VERSION=0.1.0-dev
ENV VELIN_VERSION=${VELIN_VERSION}
LABEL org.opencontainers.image.title="Velin Music" \
    org.opencontainers.image.version="${VELIN_VERSION}"
WORKDIR /app
COPY --from=vendor /app /app
COPY --from=owntone-build /tmp/build/usr/ /usr/
COPY --from=source-verifier --chmod=0644 /source/docker/owntone/owntone.conf /etc/owntone/owntone.conf
COPY --from=source-verifier /source/database/migrations /opt/velin/migrations
COPY --from=initial-plugin-build /out/ /opt/velin/initial-plugins/
COPY --from=source-verifier /source/docker/entrypoint.sh /usr/local/bin/velin-entrypoint
RUN set -eux; \
    chmod 0755 /usr/local/bin/velin-entrypoint /app/bin/velin /app/bin/docker-bootstrap /app/bin/ffmpeg /app/bin/ffprobe \
        /app/bin/fpcalc /app/bin/opencc /app/bin/velin-dlna-helper /app/bin/velin-library-watch-helper \
        /app/bin/velin-media-gateway /app/bin/velin-airplay-companion /app/bin/velin-redis-companion; \
    chmod 0555 /app/bin/webdav-range-proxy.php; \
    mkdir -p /data/config /data/database /data/runtime /data/plugins /data/cache/scrape \
        /data/cache/artist-database /data/cache/owntone /data/runtime/airplay /data/runtime/redis /data/redis \
        /storage/music /storage/downloads; \
    ln -s /data /app/docker-data; \
    ln -s /storage /app/storage; \
    ln -s /data/database /app/database; \
    ln -s /data/runtime /app/runtime; \
    ln -s /data/plugins /app/plugin; \
    chmod 0750 /data/plugins; \
    touch /app/.velin-container; chmod 0444 /app/.velin-container

EXPOSE 8787
ENTRYPOINT ["/usr/local/bin/velin-entrypoint"]
CMD ["php", "start.php", "start"]
