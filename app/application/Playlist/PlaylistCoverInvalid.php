<?php

declare(strict_types=1);

namespace app\application\Playlist;

/**
 * 表示歌单封面的大小、声明 MIME、真实图片、尺寸或解码结果不符合固定上传契约。
 *
 * 异常不携带原始字节、客户端文件名、图片元数据或解码器诊断；Controller 将其稳定映射为 422，
 * 因而既能给客户端明确反馈，也不会把不受信输入写入日志。
 */
final class PlaylistCoverInvalid extends PlaylistInvalid
{
}
