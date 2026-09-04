# syntax=docker/dockerfile:1.7

ARG ALPINE_REPOSITORY=https://mirrors.aliyun.com/alpine

# 公开仓库内容由私有 CI 生成清单；镜像构建先校验全部受管文件，再从该只读阶段复制运行内容。
FROM alpine:3.22 AS source-verifier
WORKDIR /source
COPY . .
RUN sha256sum -c CONTENTS.sha256

# 公开仓库只接收私有 CI 生成的 public 静态文件和 amd64 Go Helper；源码不会在镜像构建阶段重新进入。
FROM php:8.3-cli-alpine3.22 AS php-runtime-base

ARG ALPINE_REPOSITORY
RUN set -eux; \
    sed -i "s#https://dl-cdn.alpinelinux.org/alpine#${ALPINE_REPOSITORY}#g" /etc/apk/repositories; \
    apk add --no-cache \
        curl \
        freetype \
        libjpeg-turbo \
        libpng \
        libwebp \
        libzip \
        chromaprint \
        nodejs \
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
COPY --from=source-verifier /source/database/migrations /opt/velin/migrations
COPY --from=source-verifier /source/initial-plugins/manifest.json /opt/velin/initial-plugins/manifest.json
COPY --from=source-verifier /source/release-assets/plugins/metadata-scrape-0.0.4.zip /opt/velin/initial-plugins/metadata-scrape-0.0.4.zip
COPY --from=source-verifier /source/docker/entrypoint.sh /usr/local/bin/velin-entrypoint
RUN set -eux; \
    chmod 0755 /usr/local/bin/velin-entrypoint /app/bin/velin /app/bin/docker-bootstrap /app/bin/ffmpeg /app/bin/ffprobe \
        /app/bin/fpcalc /app/bin/opencc /app/bin/velin-dlna-helper /app/bin/velin-library-watch-helper \
        /app/bin/velin-media-gateway; \
    chmod 0555 /app/bin/webdav-range-proxy.php; \
    mkdir -p /data/config /data/database /data/runtime /data/plugins /data/cache/scrape \
        /data/cache/artist-database /storage/music /storage/downloads; \
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
