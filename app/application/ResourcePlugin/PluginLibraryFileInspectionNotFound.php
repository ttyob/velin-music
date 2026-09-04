<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

/** 文件不存在或当前管理员没有该文件所属音乐库的 manage 范围。 */
final class PluginLibraryFileInspectionNotFound extends \RuntimeException {}
