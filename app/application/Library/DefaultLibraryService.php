<?php

declare(strict_types=1);

namespace app\application\Library;

use Symfony\Component\Uid\Ulid;
use support\Db;
use stdClass;
use Throwable;

/**
 * 读取系统唯一默认音乐库的数据库指针。
 *
 * 默认库不能再由固定 ID 或固定路径推断；指针必须指向一个 active 音乐库。配置缺失或损坏时返回
 * 未配置状态，让登录后的初始化页面接管，而不是猜测另一个库。该服务只读数据库，不创建、移动或
 * 删除媒体文件。
 */
final readonly class DefaultLibraryService
{
    public const SETTING_KEY = 'default_library_id';

    public function id(): ?string
    {
        try {
            $setting = Db::table('system_settings')->where('setting_key', self::SETTING_KEY)->first();
        } catch (Throwable) {
            return null;
        }
        if (!$setting instanceof stdClass) {
            return null;
        }
        $value = json_decode((string) $setting->value_json, true);
        if (!is_string($value) || !Ulid::isValid($value)) {
            return null;
        }
        $exists = Db::table('music_libraries')->where('id', $value)->where('status', 'active')->exists();
        return $exists ? $value : null;
    }

    public function isConfigured(): bool
    {
        return $this->id() !== null;
    }
}
