# syntax=docker/dockerfile:1.7

ARG ALPINE_REPOSITORY=https://mirrors.aliyun.com/alpine
ARG TARGETARCH=amd64

# 公开仓库只接收私有 CI 生成的 public 静态文件和双架构 Go Helper；源码不会在镜像构建阶段重新进入。
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

FROM app-base AS media-runtime-amd64
COPY --chmod=0755 bin/ffmpeg bin/ffprobe /app/bin/
COPY --chmod=0644 licenses/ffmpeg-static.GPL-3.0.txt /app/bin/FFPROBE-LICENSE
RUN set -eux; /app/bin/ffprobe -version >/dev/null; /app/bin/ffmpeg -version >/dev/null

FROM app-base AS media-runtime-arm64
ARG FFPROBE_RELEASE=b6.1.1
ARG FFPROBE_DOWNLOAD_PREFIX=https://gh-proxy.com/
ARG FFPROBE_ARM64_SHA256=d17ae9b4c297d48e2521ba14e417bb0537c6ff77c584cdbcd6bb0d8d0307a2e8
ARG FFMPEG_ARM64_SHA256=6bb182d0d75d23028db82e9e4f723ca69b853d055698486e6984ddb2c06fb8ce
RUN set -eux; \
    mkdir -p /app/bin; \
    ffprobe_url="https://github.com/eugeneware/ffmpeg-static/releases/download/${FFPROBE_RELEASE}/ffprobe-linux-arm64"; \
    ffmpeg_url="https://github.com/eugeneware/ffmpeg-static/releases/download/${FFPROBE_RELEASE}/ffmpeg-linux-arm64"; \
    curl --fail --location --retry 5 --retry-all-errors --connect-timeout 20 --output /app/bin/ffprobe "${FFPROBE_DOWNLOAD_PREFIX}${ffprobe_url}"; \
    curl --fail --location --retry 5 --retry-all-errors --connect-timeout 20 --output /app/bin/ffmpeg "${FFPROBE_DOWNLOAD_PREFIX}${ffmpeg_url}"; \
    echo "${FFPROBE_ARM64_SHA256}  /app/bin/ffprobe" | sha256sum -c -; \
    echo "${FFMPEG_ARM64_SHA256}  /app/bin/ffmpeg" | sha256sum -c -; \
    chmod 0755 /app/bin/ffprobe /app/bin/ffmpeg; \
    /app/bin/ffprobe -version >/dev/null; /app/bin/ffmpeg -version >/dev/null
COPY --chmod=0644 licenses/ffmpeg-static.GPL-3.0.txt /app/bin/FFPROBE-LICENSE

FROM media-runtime-${TARGETARCH} AS media-runtime-base
RUN set -eux; \
    mv /usr/bin/fpcalc /app/bin/fpcalc; \
    apk add --no-cache opencc; \
    mv /usr/bin/opencc /app/bin/opencc; \
    mv /usr/share/opencc /app/bin/opencc-data; \
    /app/bin/fpcalc -version >/dev/null; \
    /app/bin/opencc --version >/dev/null 2>&1; \
    test -r /app/bin/opencc-data/t2s.json

# 两个架构的 Helper 已在私有 CI 构建并验证为静态 ELF；公开镜像只按 TARGETARCH 选择对应结果。
FROM scratch AS helper-amd64
COPY artifacts/bin/linux-amd64/velin-dlna-helper /out/velin-dlna-helper
COPY artifacts/bin/linux-amd64/velin-library-watch-helper /out/velin-library-watch-helper
FROM scratch AS helper-arm64
COPY artifacts/bin/linux-arm64/velin-dlna-helper /out/velin-dlna-helper
COPY artifacts/bin/linux-arm64/velin-library-watch-helper /out/velin-library-watch-helper
FROM helper-${TARGETARCH} AS helper

FROM media-runtime-base AS vendor
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY app ./app
COPY config ./config
COPY public ./public
COPY support ./support
COPY bin/velin ./bin/velin
COPY bin/webdav-range-proxy.php ./bin/webdav-range-proxy.php
COPY --from=helper /out/velin-dlna-helper ./bin/velin-dlna-helper
COPY --from=helper /out/velin-library-watch-helper ./bin/velin-library-watch-helper
COPY licenses/goupnp.LICENSE ./bin/goupnp.LICENSE
COPY phinx.php start.php ./
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

FROM media-runtime-base AS runtime
WORKDIR /app
COPY --from=vendor /app /app
COPY database/migrations /opt/velin/migrations
COPY docker/entrypoint.sh /usr/local/bin/velin-entrypoint
RUN set -eux; \
    chmod 0755 /usr/local/bin/velin-entrypoint /app/bin/velin /app/bin/ffmpeg /app/bin/ffprobe \
        /app/bin/fpcalc /app/bin/opencc /app/bin/velin-dlna-helper /app/bin/velin-library-watch-helper; \
    chmod 0555 /app/bin/webdav-range-proxy.php; \
    mkdir -p /app/database /app/runtime /app/plugin /media/library /media/cache/scrape; \
    chmod 0750 /app/plugin; \
    touch /app/.velin-container; chmod 0444 /app/.velin-container

EXPOSE 8787
ENTRYPOINT ["/usr/local/bin/velin-entrypoint"]
CMD ["php", "start.php", "start"]
