# Velin Music Docker 部署包 <VERSION>

这个目录是公开发布的部署包，不包含 Velin Music 私有源码。它只通过 Docker 拉取已经由 CI 构建的
backend 镜像 `<IMAGE>`；镜像已经包含 PHP 应用、前端静态文件、FFmpeg、FFprobe 和 Go Helper。当前公开
平台为 `linux/amd64`，Release 中带二进制的资源插件也按同一平台验证。

## 启动

```bash
cp .env.docker.example .env
docker login <镜像仓库>
docker compose pull
docker compose up -d --wait
docker compose ps
```

解压后可先执行 `sha256sum -c CONTENTS.sha256`。部署包中的 Compose 和环境模板已经固定到镜像 digest，
不会因远端同名标签变化而静默升级。

首次启动时 backend entrypoint 会在 `.env` 缺失或相关值未初始化时生成两把独立密钥和一个指标 Bearer Token。已有有效值不会
轮换。核心迁移完成后，镜像会通过通用插件安装器默认初始化 `metadata-scrape`；同一数据根只执行一次，
已有插件和配置不会覆盖，管理员后来卸载也不会在重启时自动恢复。数据库、插件、runtime 和缓存位于
`docker-data/`，媒体位于 `storage/`，升级镜像不会删除它们。
可选自动部署会停掉 backend 后把 SQLite 冷备份写入 `docker-data/database/deploy-backups/`；停止后的
备份、环境切换、启动或健康检查任一步失败都会拉起旧环境。新 backend 已经启动过时会恢复升级前数据库，
原本没有数据库时会移除失败版本新建的数据库；回滚自身失败会保留冷备份并明确要求人工恢复。备份包含
业务数据，不得上传到代码仓库。

## 发布包边界

包内只有 Compose、初始化脚本、OwnTone 配置和空的数据目录；不包含 `frontend-new`、Go 源码、PHP
源码、测试、依赖缓存、SQLite 数据、媒体文件、日志或任何密钥。升级时应使用新 Release 提供的 digest
部署包；数据库迁移后的回滚必须同时恢复升级前冷备份，不能只改回镜像标签。

不要执行 `docker compose down -v`，否则会删除 Redis 持久卷。生产密钥由 entrypoint 生成在
`docker-data/config/.env`，不得提交到公开仓库或写入镜像；部署根 `.env` 只负责 Compose 镜像选择。
