<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * 退役用户端 Last.fm、ListenBrainz 与 Maloja 外部播放记录同步。
 *
 * 播放事件和 Subsonic scrobble 仍保留为 Velin 本地播放统计协议；本迁移只删除第三方连接凭据和已排队
 * 的远端投递任务。必须先删除子表再删除连接表，以满足 SQLite 外键约束。升级前应停止旧版外部投递
 * Worker；新版本不再创建任务、读取凭据或注册用户连接 API。删除操作不触碰 playback_events、播放会话、
 * 播放次数和听歌时长等本地业务事实。
 *
 * 迁移不可逆：第三方密文和未发送队列属于已退役功能的隐私数据，不能在回滚时凭空恢复；如未来重新
 * 支持外部同步，必须重新设计授权、保留策略和迁移，而不是复用已删除的凭据。
 */
final class RemoveExternalScrobble extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('scrobble_delivery_jobs')) {
            $this->execute('DROP TABLE scrobble_delivery_jobs');
        }
        if ($this->hasTable('scrobble_connections')) {
            $this->execute('DROP TABLE scrobble_connections');
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('外部 Scrobble 已退役，不能恢复已删除的凭据和投递任务。');
    }
}
