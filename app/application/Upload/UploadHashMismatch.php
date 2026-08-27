<?php

declare(strict_types=1);

namespace app\application\Upload;

use RuntimeException;

/**
 * 表示完整暂存文件与会话建立时声明的 SHA-256 不一致。
 *
 * 该错误只在 Worker 重新读取完整文件后产生，不包含哈希值或路径。文件保留在受控暂存中，
 * 不会发布到动态检测目录；用户应取消会话并重新上传，不允许仅修改数据库期望哈希继续。
 */
final class UploadHashMismatch extends RuntimeException
{
}
