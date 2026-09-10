# Velin Music 0.1.30

<p align="center">
  <strong>自托管音乐库、播放器与媒体服务</strong><br>
  在自己的服务器上整理、搜索、播放和管理音乐，数据始终留在自己的存储中。
</p>

<p align="center">
  <a href="https://github.com/ttyob/velin-music/releases/latest">最新版本</a> ·
  <a href="https://github.com/ttyob/velin-music/pkgs/container/velin-music">GHCR 镜像</a> ·
  <a href="https://github.com/ttyob/velin-music/releases">发布包</a>
</p>

## 项目简介

Velin Music 是面向个人、家庭和小型团队的自托管音乐服务。它以现有音乐文件为中心建立索引，提供
Web 播放器、歌词与封面、收藏和播放列表，并通过管理后台完成曲库扫描、任务、插件和系统设置。
原始音频不会因为刮削或整理而被移动或改名。

本仓库定位为 Velin Music 的公开 backend 发布与部署仓库，由私有源码仓库导出可运行构建版本，包含
脱敏 PHP backend 源码、数据库迁移、前端静态文件和已在 Alpine 中验证的 `linux/amd64` Helper；不包含
私有前端与 Go 源码、用户数据、媒体文件或凭据。独立的
[Velin Music 插件库](https://github.com/ttyob/velin-music-plugins) 提供插件索引和历史版本；公开仓库也
保留构建当前 backend 镜像所需的已审查插件归档，但精简部署包不重复携带它们。

## 功能

- **音乐库**：扫描本地曲库，按歌曲、专辑、艺人、流派和年份浏览，支持全文搜索。
- **播放体验**：队列、最近播放、收藏、播放列表、随机推荐、播放进度恢复和响应式 Web 播放器。
- **媒体处理**：FFmpeg/FFprobe 转码，歌词显示与时间轴，封面和描述元数据刮削，独立可重建的缓存。
- **管理后台**：媒体库配置、增量扫描、上传、重复项检查、回收站、任务历史、用户和隐私设置。
- **家庭设备**：通过 DLNA 和 OwnTone/AirPlay 将音乐投放到局域网设备；WebDAV 曲库支持按需读取。
- **插件扩展**：公开版本包含元数据刮削、LX Music 和 Jackett 插件，可在后台查看运行状态。
- **安全与可靠性**：Session/CSRF/对象授权、SQLite WAL 与迁移、Redis 队列、不可变镜像摘要和校验清单。

## 预览

固定深色界面适配桌面和移动浏览器，以下截图来自实际 Web 客户端。页面内容会根据账户权限和媒体数据展示。

<p align="center">
  <img src="previews/home-preview.png" alt="Web 首页预览" width="32%">
  <img src="previews/now-playing-preview.png" alt="正在播放预览" width="32%">
  <img src="previews/admin-preview.png" alt="管理后台预览" width="32%">
</p>

<p align="center"><sub>Web 首页 · 正在播放 · 管理后台</sub></p>

## 架构

```text
浏览器 / App
     │
     ▼
Go Media Gateway :8787
     ├── /api/*、认证和写操作 ─────► Webman/PHP :18787 (容器内回环)
     └── 图片、JS、CSS、字体、原始媒体 ─► 直接读取并发送
                                      │
                         SQLite + Redis + /storage
```

Go 网关只负责高并发的静态和媒体读取；Webman 负责认证、授权、数据库事务、任务编排和审计。因此图片、
字体或脚本请求不会占用等待 API 数据的 PHP Worker。

## 安装

### 环境要求

- Linux 主机，Docker Engine 24+ 和 Docker Compose v2
- `linux/amd64`（当前公开镜像只发布此架构）
- 至少一个用于保存音乐的磁盘目录；生产环境建议使用 SSD 保存 `docker-data`

### Git clone 直接部署

公开稳定标签已经包含默认 `compose.yaml` 和空持久目录。Compose 只拉取已构建镜像，不会在部署主机
执行 Composer、npm 或 Go 构建；首次启动由 backend 容器原子生成密钥。

```bash
git clone https://github.com/ttyob/velin-music.git
cd velin-music
docker compose up -d --remove-orphans --wait
docker compose ps
```

若默认镜像仓库在当前网络不可达，可在部署根新建 `.env`，只覆盖经发布方验证的镜像地址后再启动：

```dotenv
VELIN_BACKEND_IMAGE=<受信仓库>/velin-music@sha256:<摘要>
```

Redis、OwnTone、Avahi 与 D-Bus 已包含在同一个 backend 镜像中。覆盖地址必须来自可信仓库并与发布方公布
的摘要一致；不要使用来源和缓存内容无法审计的公共加速地址。正式稳定版将同时公布国内可访问的镜像地址
与校验信息。

公开 `main` 始终对应最近一次稳定导出。需要精确复现版本时使用
`git clone --branch v0.1.30 --depth 1 https://github.com/ttyob/velin-music.git`；稳定 Git 标签与同版本
镜像标签均禁止覆盖。

### 使用 Release 精简部署包

Release 部署包只包含 Compose、初始化脚本和空持久目录，其中 `compose.yaml` 固定到本次 backend 镜像
digest。源码无需重复打包，可直接使用公开仓库或 GitHub 自动生成的 Source code 附件。

```bash
VERSION=0.1.30
curl -fL -o "velin-music-deploy-${VERSION}.tar.gz" \
  "https://github.com/ttyob/velin-music/releases/download/v${VERSION}/velin-music-deploy-${VERSION}.tar.gz"
tar -xzf "velin-music-deploy-${VERSION}.tar.gz"
cd "velin-music-deploy-${VERSION}"

# 可选但推荐：确认部署配置未被篡改
sha256sum -c CONTENTS.sha256

docker compose up -d --remove-orphans --wait
docker compose ps
```

浏览器打开 `http://服务器地址:8787`，按首次启动页面创建管理员账户。首次启动会自动生成认证密钥、凭据
密钥和指标令牌；有效配置不会在重启时轮换。将 `VELIN_PUBLIC_URL` 设置为实际 HTTPS 地址后，系统会启用
安全 Cookie。

### 目录布局

| 宿主机目录 | 容器路径 | 用途 |
| --- | --- | --- |
| `docker-data/` | `/data` | SQLite、配置、运行时文件、插件和刮削缓存 |
| `docker-data/redis/` | `/data/redis` | Redis AOF/RDB 持久数据 |
| `docker-data/cache/scrape/` | `/data/cache/scrape` | 可重建的歌词、封面和刮削结果 |
| `storage/music/` | `/storage/music` | 音乐库原始文件 |
| `storage/downloads/` | `/storage/downloads` | 插件下载的临时或待整理文件 |

音乐库和缓存是两套独立根目录。请确保 Docker 进程对这两个宿主目录有正确的读写权限，不要把数据库、
密钥或整个宿主机根目录挂载进容器。

## 日常操作

```bash
# 查看服务状态和日志
docker compose ps
docker compose logs -f --tail=200 backend

# 升级到新的稳定标签或 Release 部署包后执行
docker compose up -d --remove-orphans --wait

# 停止服务（保留数据）
docker compose stop
```

升级前请保留 `docker-data/database/` 的备份；Redis 数据与其他运行数据一样保存在 `docker-data/redis/`，
不再使用独立命名卷。部署包配套的自动升级流程会在迁移前创建 SQLite 冷备份，健康检查失败时恢复旧环境。

## 插件

| 插件 | 用途 |
| --- | --- |
| `metadata-scrape` | 获取歌曲、专辑和艺人的描述元数据、歌词与封面 |
| `lx-music` | 对接 LX Music 脚本 Runner，搜索和补充音乐资源 |
| `jackett` | 对接 Jackett 索引器，管理搜索订阅和下载任务 |

管理员可以在后台的资源插件页面查看版本、启停插件和检查健康状态。插件归档只在首次初始化时安装到
`docker-data/plugins`，不会覆盖已经存在的配置。私有或商业插件不包含在公开 Release 中。

## 发布文件

每个稳定 `vX.Y.Z` Release 通常包含：

- `velin-music-deploy-X.Y.Z.tar.gz`：digest 固定的精简 Docker Compose 部署包
- `SHA256SUMS` 与部署包内的 `CONTENTS.sha256`：文件完整性校验
- GitHub 自动生成的 Source code ZIP/TAR：与稳定标签一致的完整公开 backend 源码
- GHCR 中的 `linux/amd64` 镜像、SBOM、构建证明和签名

公开插件归档和 `index.json` 位于独立的
[Velin Music 插件库](https://github.com/ttyob/velin-music-plugins)，Velin Music Release 不重复附加插件 ZIP。
后台插件应用商店可直接填写该仓库首页；服务端会将规范 GitHub 仓库地址转换为 main 分支的 Raw
`index.json`，也继续接受完整 Raw 索引直链。

Git 标签部署使用同版本不可变镜像标签；Release 精简部署包固定镜像 digest，适合要求供应链精确复现的
生产环境。发布流程不会覆盖已经存在的版本标签或附件。

## 限制与安全提示

- 当前不提供 arm64 镜像；不要在 ARM 主机上强行拉取 amd64 镜像作为生产方案。
- 服务默认绑定宿主机 `8787`，请在反向代理或防火墙中限制管理入口，并为公网访问配置 HTTPS。
- 媒体文件、SQLite 数据库、日志和密钥不属于公开仓库内容；请把备份存放在受控位置。
- 自动刮削只写入数据库或配置的派生缓存；替换已有相邻封面、歌词和音频标签需要单独的管理操作。

## 许可证

公开 backend 的许可证和第三方组件声明见仓库中的 [`LICENSE`](LICENSE) 与 `licenses/`。各插件可能有独立的
许可证和服务条款，使用前请查看对应插件说明。
