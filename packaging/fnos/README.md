# Velin Music fnOS Docker FPK

该目录保存飞牛 fnOS Docker 型 FPK 的受审模板。FPK 不包含源码、镜像层、密钥、数据库或媒体，安装时由
fnOS Docker 项目拉取构建脚本传入的不可变 `sha256` 镜像。

## 构建

在仓库根目录执行：

```bash
scripts/build-fnos-fpk.sh \
  ghcr.io/ttyob/velin-music@sha256:<镜像摘要> \
  <VERSION> \
  dist/velin-music_<VERSION>_x86.fpk
```

输出同时包含 `.fpk` 和 `.fpk.sha256`。当前镜像中的插件 Helper 只验收了 `linux/amd64`，因此 manifest
固定为 `x86`，不得在 ARM 飞牛设备上安装。构建器会拉取传入的 digest，并在无网络、无宿主挂载的临时
容器中验证 Redis、OwnTone、Avahi、D-Bus 和生命周期 companion 均已内置，并要求镜像 OCI version
label 与 FPK 版本一致；旧三容器镜像或版本错配镜像不能生成 FPK。公开稳定 Release 同时附带 FPK、独立
校验文件和包含部署包/FPK 的 `SHA256SUMS`，应用内更新提示会优先显示该 FPK 官方下载地址。
FPK 桌面与应用中心使用 `assets/icon-256.png` 和 `assets/icon-64.png` 两张专用 RGBA 图标；约 20% 画布
宽度的圆角矩形内
使用不透明深色底板，矩形外四角透明，避免 fnOS 不裁切第三方图标时出现圆边与尖角并存。标志占比也
针对小尺寸显示优化，但不修改 Flutter、macOS 或 Web 使用的品牌源图。构建器会校验尺寸、8 位 RGBA
编码，并逐像素确认四角透明、中心底板不透明。桌面入口使用 `images/{0}.png?v=<FPK 版本>`，构建时
自动写入当前 SemVer，使 fnOS 在每次安装新版本后使用新的图标缓存键。

## 安装和数据

在 fnOS 应用中心选择“手动安装”并上传 `.fpk`。应用使用 host 网络，Web 地址为
`http://<NAS-IP>:8787/`；DLNA/AirPlay 需要局域网允许 `1900/udp`、`5353/udp`。

安装向导可填写应用数据目录；留空时使用 fnOS 分配的 `TRIM_PKGVAR/docker-data`，并映射为 `/data`。
音乐库目录必须填写已存在的 `/volN/...` 宿主目录并映射为 `/storage`，例如 `/vol3/1000/歌曲`。fnOS
当前安装向导没有原生目录选择控件，所以路径由文本字段收集，并在 Docker 项目创建前由脚本再次校验。
首次 Web 初始化通过文件管理器式页面浏览 `/storage`，并同时选择歌词/封面存储策略和扫描模式；容器和
Web 都不会显示宿主绝对路径。升级始终保留数据。卸载向导默认保留；显式选择“清理应用数据”会永久
删除 SQLite、密钥、Redis、插件、独立缓存和运行文件，但音乐库目录及其中所有文件（包括相邻模式生成
的歌词和封面）始终保留。清理前仍应先备份 SQLite 和配置。

fnOS 会在 `install_callback` 之前初始化 Docker 项目。`install_init` 因此必须先幂等建立
默认 `TRIM_PKGVAR/docker-data` 及其固定子目录，或复验用户指定的数据目录，然后才能让
`create_host_path: false` 在路径异常时失败关闭。音乐库目录从不由安装脚本自动创建，两个真实目录不能
相同或相互包含。
`install_callback` 保留第二次幂等复核，任一阶段都不得覆盖或删除已有数据。
安装与升级会在数据根和 `TRIM_PKGVAR` 分别原子记录 Velin 标记及规范双根。卸载清理只有在两个记录完全
匹配且固定受管子目录未被链接替换时执行；自定义数据根中的其他文件不会删除，根目录也会保留。
fnOS 在该时点尚未把 `TRIM_PKGVAR` 顶层目录授权给套件账号，所以生命周期脚本使用
`run-as: root` 建立精确的 fnOS 应用数据路径。该权限不会传递为 Docker `privileged`，Compose 仍不挂载
Docker Socket、宿主 PID 或未授权目录。
