<?php

declare(strict_types=1);

namespace app\application\Library;

use support\Db;

/**
 * 按持久化来源类型选择只读远端客户端。
 *
 * 调用方只能提交内部 library ID；本工厂重新读取来源，不相信扫描任务或播放请求携带的类型快照。未知、
 * 本地或已删除库失败关闭，避免把网络库存定位符交给错误协议解释。具体工厂负责用途隔离密文解密。
 */
final readonly class RemoteLibraryClientFactory
{
    public function __construct(
        private WebDavClientFactory $webDav = new WebDavClientFactory(),
        private OneDriveClientFactory $oneDrive = new OneDriveClientFactory(),
        private GoogleDriveClientFactory $googleDrive = new GoogleDriveClientFactory(),
    ) {
    }

    /** 返回当前连接事实对应的客户端；不缓存凭据或访问令牌。 */
    public function forLibrary(string $libraryId): RemoteLibraryClient
    {
        $sourceType = Db::table('music_libraries')->where('id', $libraryId)->value('source_type');
        return match ($sourceType) {
            'webdav' => $this->webDav->forLibrary($libraryId),
            'onedrive' => $this->oneDrive->forLibrary($libraryId),
            'google_drive' => $this->googleDrive->forLibrary($libraryId),
            default => throw new RemoteLibraryUnavailable('REMOTE_LIBRARY_CONFIGURATION_MISSING', '网络音乐库配置不存在。'),
        };
    }

    /**
     * 返回同一连接事实的受控写客户端。
     *
     * 写接口与扫描/播放只读接口分离，调用方必须显式选择本方法；本地库、停用后遗留类型和尚未实现安全
     * 非覆盖发布的客户端全部失败关闭，不能因对象同时实现只读接口就隐式获得写权限。
     */
    public function forWritableLibrary(string $libraryId): WritableRemoteLibraryClient
    {
        $client = $this->forLibrary($libraryId);
        if (!$client instanceof WritableRemoteLibraryClient) {
            throw new RemoteLibraryUnavailable('REMOTE_LIBRARY_WRITE_UNSUPPORTED', '网络音乐库不支持受控上传。');
        }
        return $client;
    }
}
