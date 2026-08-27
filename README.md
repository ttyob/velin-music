# Velin Music backend 0.1.0

这是由 Velin Music 私有源码仓库自动导出的公开 backend 构建仓库。它只包含运行 backend 所需的 PHP
代码、数据库迁移、镜像内静态前端文件，以及已经由私有 CI 构建并验证的 amd64/arm64 Go Helper。

公开仓库不包含用户数据库、媒体文件、运行时数据、环境文件、账号凭据或前端/Go 源码。镜像发布由
`.github/workflows/publish-image.yml` 完成，推送版本标签后会生成多架构镜像、SBOM、构建证明并签名。

## 镜像

```text
ghcr.io/<组织名>/velin-music-backend:0.1.0
```

部署时请使用公开部署包中的 `compose.yaml`。部署包负责提供 SQLite、媒体、runtime 和插件目录，镜像
只提供不可变的应用运行层。

## 本地校验

```bash
docker buildx build --platform linux/amd64,linux/arm64 -t velin-music-backend:0.1.0 .
```

发布前必须确认两个架构的 `/app/bin/velin-dlna-helper --version`、
`/app/bin/velin-library-watch-helper --version` 和 `/app/public/index.html` 均存在。
