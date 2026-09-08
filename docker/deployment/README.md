# Velin Music Docker 精简部署包 0.1.28

这个目录只包含 Docker Compose、初始化脚本、OwnTone 配置和空持久目录，不重复包含 GitHub 标签已经
提供的公开源码。Compose 只拉取已经由 CI 构建的 backend 镜像 `ghcr.io/ttyob/velin-music:0.1.28`，不会在部署主机重新安装依赖
或构建源码。当前公开平台为 `linux/amd64`。

## 启动

```bash
docker compose up -d --wait
docker compose ps
```

公开 GHCR 镜像不要求登录；使用私有镜像源时才需要先执行 `docker login`。解压后可先执行
`sha256sum -c CONTENTS.sha256`。部署包中的 Compose 和环境模板已经固定到镜像 digest，
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

包内不包含 PHP/前端/Go 源码、测试、依赖缓存、SQLite 数据、媒体文件、日志或任何密钥。完整公开源码由
仓库及 GitHub 自动生成的 Source code 附件提供。升级时应使用新 Release 的 digest 固定部署包；数据库
迁移后的回滚必须同时恢复升级前冷备份，不能只改回镜像标签。

不要执行 `docker compose down -v`，否则会删除 Redis 持久卷。生产密钥由 entrypoint 生成在
`docker-data/config/.env`，不得提交到公开仓库或写入镜像；部署根 `.env` 只是可选的 Compose 镜像覆盖
配置，默认启动不要求创建。

默认仓库无法访问时，可在部署根 `.env` 中设置 `VELIN_BACKEND_IMAGE`、`VELIN_REDIS_IMAGE` 和
`VELIN_OWNTONE_IMAGE`，再执行 Compose。覆盖值只应使用发布方同步并公布校验信息的可信镜像；不得把
来源不明的公共加速地址作为生产依赖。首个正式稳定版发布前必须提供国内可访问的受信镜像地址。
