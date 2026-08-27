<?php

declare(strict_types=1);

namespace app\application\Scrape;

use JsonException;

/**
 * 表示一次渠道查询的有界、安全诊断摘要。
 *
 * 该值对象只接收主项目已经归一化的查询证据，以及内置平台查询器返回的 Provider outcome 标量。它不会
 * 保存文件路径、服务地址、请求 Header、Cookie、Token、第三方原始正文、候选完整响应或歌词内容。
 * version=2 额外保存最多十条由本地受控进程标准化后的候选评估，使管理员能够看到哪些候选被评分策略
 * 排除；候选只含描述字段、聚合分数、稳定理由码和采用判定，不含外部 ID 或资源定位符。
 * 编码结果可随渠道快照持久化并由具备音乐库管理权限的审核详情读取；任何未知字段、越界值或损坏 JSON
 * 都失败关闭为“无诊断”，不能阻止其他合法渠道显示，也不能参与最终元数据或标签写回。
 */
final readonly class ScrapeProviderDiagnostics
{
    private const STATUSES = ['matched', 'empty', 'low_confidence', 'failed', 'not_queried'];

    /**
     * @param list<string> $keywords 通用关键词服务从本地标题生成的实际查询词，最多 20 项。
     * @param list<string> $artists 提交给独立服务的规范艺术家证据，最多 20 项。
     * @param list<array<string,mixed>> $candidates 渠道按分数排序的前十条标准候选评估。
     */
    public function __construct(
        public array $keywords,
        public string $title,
        public array $artists,
        public ?string $album,
        public ?int $durationMs,
        public ?string $isrc,
        public string $status,
        public int $candidateCount,
        public ?int $bestScore,
        public ?string $errorCode,
        public ?int $httpStatus,
        public ?int $latencyMs,
        public string $decisionCode,
        public array $candidates = [],
        public bool $candidatesTruncated = false,
    ) {
        $this->assertStringList($keywords, 20, 500, '查询关键词');
        $this->assertStringList($artists, 20, 300, '艺术家证据');
        if (trim($title) === '' || mb_strlen($title) > 500) {
            throw new ScrapeProviderDiagnosticsInvalid('渠道诊断标题无效。');
        }
        if ($album !== null && (trim($album) === '' || mb_strlen($album) > 500)) {
            throw new ScrapeProviderDiagnosticsInvalid('渠道诊断专辑无效。');
        }
        if ($durationMs !== null && ($durationMs < 1 || $durationMs > 86_400_000)) {
            throw new ScrapeProviderDiagnosticsInvalid('渠道诊断时长无效。');
        }
        if ($isrc !== null && preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/', $isrc) !== 1) {
            throw new ScrapeProviderDiagnosticsInvalid('渠道诊断 ISRC 无效。');
        }
        if (!in_array($status, self::STATUSES, true) || $candidateCount < 0 || $candidateCount > 100
            || ($bestScore !== null && ($bestScore < 0 || $bestScore > 100))
            || ($errorCode !== null && preg_match('/^[A-Z][A-Z0-9_]{2,99}$/', $errorCode) !== 1)
            || ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599))
            || ($latencyMs !== null && ($latencyMs < 0 || $latencyMs > 30_000))
            || preg_match('/^[A-Z][A-Z0-9_]{2,99}$/', $decisionCode) !== 1) {
            throw new ScrapeProviderDiagnosticsInvalid('渠道执行诊断字段无效。');
        }
        $this->assertCandidateEvaluations($candidates);
        if ($candidatesTruncated && count($candidates) >= $candidateCount) {
            throw new ScrapeProviderDiagnosticsInvalid('渠道候选截断状态无效。');
        }
    }

    /**
     * 从本次实际提交的查询与一条平台执行摘要创建诊断。
     *
     * `$query` 只能来自 Worker 根据冻结文件证据构造的标准证据，`$attempt` 只能来自严格校验后的受控
     * Provider 协议结果。decisionCode 是便于页面解释的稳定归类，不覆盖更精确的 errorCode。
     * 本方法没有数据库或网络副作用，重复输入产生相同 JSON 语义。
     *
     * @param array<string,mixed> $query
     * @param list<string> $keywords
     * @param array<string,mixed> $attempt
     */
    public static function fromAttempt(array $query, array $keywords, array $attempt): self
    {
        $status = (string) ($attempt['status'] ?? 'failed');
        $decision = match ($status) {
            'matched' => 'MATCH_ACCEPTED',
            'empty' => 'NO_CANDIDATES',
            'low_confidence' => 'BELOW_ACCEPT_THRESHOLD',
            'not_queried' => 'NOT_QUERIED',
            default => 'PROVIDER_REQUEST_FAILED',
        };
        return new self(
            array_values($keywords),
            (string) ($query['title'] ?? ''),
            array_values(is_array($query['artists'] ?? null) ? $query['artists'] : []),
            is_string($query['album'] ?? null) ? $query['album'] : null,
            is_int($query['durationMs'] ?? null) ? $query['durationMs'] : null,
            is_string($query['isrc'] ?? null) ? strtoupper($query['isrc']) : null,
            $status,
            is_int($attempt['candidateCount'] ?? null) ? $attempt['candidateCount'] : 0,
            is_int($attempt['bestScore'] ?? null) ? $attempt['bestScore'] : null,
            is_string($attempt['errorCode'] ?? null) ? $attempt['errorCode'] : null,
            is_int($attempt['httpStatus'] ?? null) ? $attempt['httpStatus'] : null,
            is_int($attempt['latencyMs'] ?? null) ? $attempt['latencyMs'] : null,
            $decision,
            is_array($attempt['candidates'] ?? null) && array_is_list($attempt['candidates'])
                ? $attempt['candidates'] : [],
            ($attempt['candidatesTruncated'] ?? false) === true,
        );
    }

    /**
     * 编码 version=2 的固定 JSON 结构。
     *
     * JSON_THROW_ON_ERROR 保证无效 UTF-8 不会静默写入；数据库列另有 16 KiB 上限。调用方应在与渠道
     * 快照相同的短事务内写入，事务失败时两者共同回滚。
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> 返回 API 可安全展示的同一版本化结构。 */
    public function toArray(): array
    {
        return [
            'version' => 2,
            'query' => [
                'keywords' => $this->keywords,
                'title' => $this->title,
                'artists' => $this->artists,
                'album' => $this->album,
                'durationMs' => $this->durationMs,
                'isrc' => $this->isrc,
            ],
            'outcome' => [
                'status' => $this->status,
                'candidateCount' => $this->candidateCount,
                'bestScore' => $this->bestScore,
                'errorCode' => $this->errorCode,
                'httpStatus' => $this->httpStatus,
                'latencyMs' => $this->latencyMs,
                'decisionCode' => $this->decisionCode,
                'candidatesTruncated' => $this->candidatesTruncated,
            ],
            'candidates' => $this->candidates,
        ];
    }

    /**
     * 解码数据库中的历史诊断并重新执行全部边界校验。
     *
     * 兼容没有候选明细的 version=1 历史记录，并严格读取 version=2。两个版本都要求精确字段集合，
     * 未知字段不能透传到浏览器。失败抛出稳定领域异常，由详情映射器降级为 null。
     */
    public static function fromJson(string $json): self
    {
        try {
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ScrapeProviderDiagnosticsInvalid('渠道诊断 JSON 无效。', 0, $exception);
        }
        if (!is_array($value) || array_is_list($value) || !in_array($value['version'] ?? null, [1, 2], true)
            || array_keys($value) !== (($value['version'] ?? null) === 1
                ? ['version', 'query', 'outcome'] : ['version', 'query', 'outcome', 'candidates'])
            || !is_array($value['query']) || array_is_list($value['query'])
            || !is_array($value['outcome']) || array_is_list($value['outcome'])
            || array_keys($value['query']) !== ['keywords', 'title', 'artists', 'album', 'durationMs', 'isrc']
            || array_keys($value['outcome']) !== (($value['version'] ?? null) === 1
                ? ['status', 'candidateCount', 'bestScore', 'errorCode', 'httpStatus', 'latencyMs', 'decisionCode']
                : ['status', 'candidateCount', 'bestScore', 'errorCode', 'httpStatus', 'latencyMs',
                    'decisionCode', 'candidatesTruncated'])
            || (($value['version'] ?? null) === 2
                && (!is_array($value['candidates']) || !array_is_list($value['candidates'])))) {
            throw new ScrapeProviderDiagnosticsInvalid('渠道诊断结构无效。');
        }
        $query = $value['query'];
        $outcome = $value['outcome'];
        if (!is_array($query['keywords']) || !array_is_list($query['keywords'])
            || !is_string($query['title']) || !is_array($query['artists']) || !array_is_list($query['artists'])
            || ($query['album'] !== null && !is_string($query['album']))
            || ($query['durationMs'] !== null && !is_int($query['durationMs']))
            || ($query['isrc'] !== null && !is_string($query['isrc']))
            || !is_string($outcome['status']) || !is_int($outcome['candidateCount'])
            || ($outcome['bestScore'] !== null && !is_int($outcome['bestScore']))
            || ($outcome['errorCode'] !== null && !is_string($outcome['errorCode']))
            || ($outcome['httpStatus'] !== null && !is_int($outcome['httpStatus']))
            || ($outcome['latencyMs'] !== null && !is_int($outcome['latencyMs']))
            || !is_string($outcome['decisionCode'])
            || (($value['version'] ?? null) === 2 && !is_bool($outcome['candidatesTruncated']))) {
            throw new ScrapeProviderDiagnosticsInvalid('渠道诊断字段类型无效。');
        }
        return new self(
            $query['keywords'], $query['title'], $query['artists'], $query['album'], $query['durationMs'],
            $query['isrc'], $outcome['status'], $outcome['candidateCount'], $outcome['bestScore'],
            $outcome['errorCode'], $outcome['httpStatus'], $outcome['latencyMs'], $outcome['decisionCode'],
            ($value['version'] ?? null) === 2 ? $value['candidates'] : [],
            ($value['version'] ?? null) === 2 ? $outcome['candidatesTruncated'] : false,
        );
    }

    /**
     * 对将进入数据库和管理 API 的候选评估执行第二次白名单校验。
     *
     * @param list<array<string,mixed>> $candidates
     */
    private function assertCandidateEvaluations(array $candidates): void
    {
        if (!array_is_list($candidates) || count($candidates) > 10) {
            throw new ScrapeProviderDiagnosticsInvalid('渠道候选诊断数量无效。');
        }
        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || array_is_list($candidate)
                || array_keys($candidate) !== ['title', 'artists', 'album', 'durationMs', 'score',
                    'strongConflict', 'reasons', 'decisionCode']
                || !is_string($candidate['title']) || trim($candidate['title']) === ''
                || mb_strlen($candidate['title']) > 160
                || !is_array($candidate['artists']) || !array_is_list($candidate['artists'])
                || $candidate['artists'] === [] || count($candidate['artists']) > 3
                || ($candidate['album'] !== null
                    && (!is_string($candidate['album']) || trim($candidate['album']) === ''
                        || mb_strlen($candidate['album']) > 160))
                || ($candidate['durationMs'] !== null
                    && (!is_int($candidate['durationMs']) || $candidate['durationMs'] < 1
                        || $candidate['durationMs'] > 86_400_000))
                || !is_int($candidate['score']) || $candidate['score'] < 0 || $candidate['score'] > 100
                || !is_bool($candidate['strongConflict'])
                || !is_array($candidate['reasons']) || !array_is_list($candidate['reasons'])
                || count($candidate['reasons']) > 8
                || !in_array($candidate['decisionCode'], [
                    'MATCH_ACCEPTED', 'STRONG_CONFLICT', 'BELOW_ACCEPT_THRESHOLD', 'LOWER_RANKED',
                ], true)) {
                throw new ScrapeProviderDiagnosticsInvalid('渠道候选诊断字段无效。');
            }
            foreach ($candidate['artists'] as $artist) {
                if (!is_string($artist) || trim($artist) === '' || mb_strlen($artist) > 100) {
                    throw new ScrapeProviderDiagnosticsInvalid('渠道候选艺术家诊断无效。');
                }
            }
            foreach ($candidate['reasons'] as $reason) {
                if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 80
                    || preg_match('/^[A-Z][A-Z0-9_]{1,79}$/', $reason) !== 1) {
                    throw new ScrapeProviderDiagnosticsInvalid('渠道候选评分依据无效。');
                }
            }
        }
    }

    /** @param list<mixed> $values */
    private function assertStringList(array $values, int $maximumItems, int $maximumLength, string $label): void
    {
        if ($values === [] || count($values) > $maximumItems) {
            throw new ScrapeProviderDiagnosticsInvalid($label . '数量无效。');
        }
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maximumLength) {
                throw new ScrapeProviderDiagnosticsInvalid($label . '字段无效。');
            }
        }
    }
}

/** 持久诊断不符合固定安全协议；调用端必须降级而不是透传损坏内容。 */
final class ScrapeProviderDiagnosticsInvalid extends \RuntimeException
{
}
