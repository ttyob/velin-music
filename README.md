# Velin Music backend 0.1.22

这是由 Velin Music 私有源码仓库自动导出的公开 backend 构建仓库。它只包含运行 backend 所需的 PHP
代码、数据库迁移、镜像内静态前端文件，以及已经由私有 CI 构建并在 Alpine 验证的 amd64 Go Helper。

公开仓库不包含用户数据库、媒体文件、运行时数据、环境文件、账号凭据或前端/Go 源码。镜像发布由
`.github/workflows/publish-image.yml` 完成，推送稳定 `vX.Y.Z` 标签后会生成 linux/amd64 镜像、SBOM、构建
证明和签名，并创建包含 digest 固定部署包、SHA-256 清单及公开插件 ZIP 的 GitHub Release。商业或私有插件
不包含在此公开交付中。
插件 Helper 当前只有 amd64 静态产物，因此在所有插件补齐同版本 arm64 Helper 前不会声明 arm64 镜像。

## 镜像

```text
ghcr.io/<组织名>/velin-music:0.1.22
```

部署时请使用公开部署包中的 `compose.yaml`。部署包负责提供 SQLite、媒体、runtime 和插件目录，镜像
只提供不可变的应用运行层。

## 本地校验

```bash
docker buildx build --platform linux/amd64 \
  --build-arg VELIN_VERSION=0.1.22 \
  -t velin-music:0.1.22 .
```

发布前必须确认 `/app/bin/velin-dlna-helper --version`、`/app/bin/velin-library-watch-helper --version`、
`/app/public/index.html` 和健康接口版本均与 Release 一致。插件 ZIP 是待管理员审核上传的附件，不会被
镜像自动安装或覆盖 `/data/plugins` 中的现有版本。
