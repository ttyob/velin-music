<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\application\ResourcePlugin\Contract\PluginDomainEvent;
use app\infrastructure\Audit\AuditLogger;

/**
 * PluginAdminActionService 校验并执行插件公开的固定管理员动作。
 *
 * 服务只接受稳定插件 key、动作 key、核心身份和请求 ID，不接收任意配置对象。动作清单与返回值在跨越
 * 插件边界时都重新校验，避免插件把路径、URL、凭据或无界结构反射给浏览器。执行前审计失败时不会调用
 * 插件；执行后审计失败时客户端可能重试，因此插件必须以 requestId 幂等，核心不尝试回滚外部副作用。
 */
final readonly class PluginAdminActionService
{
    private const MAX_ACTIONS = 32;

    public function __construct(
        private PhpResourcePluginRegistry $registry = new PhpResourcePluginRegistry(),
        private AuditLogger $audit = new AuditLogger(),
        private PluginEventPublisher $events = new PluginEventPublisher(),
    ) {
    }

    /**
     * 返回一个活动插件经过核心重新校验的动作清单。
     *
     * 方法会执行插件的 adminActions，但不执行任何动作；空清单、重复 key、额外字段、控制字符或超长文本
     * 均失败关闭，不能向后台返回部分合法项来掩盖插件协议错误。
     *
     * @return array{actions:list<array{key:string,title:string,description:string,confirmationRequired:bool}>}
     */
    public function actions(string $pluginKey): array
    {
        return ['actions' => $this->validatedActions($this->registry->adminActions($pluginKey)->adminActions())];
    }

    /**
     * 执行一项已声明动作并返回有界结果。
     *
     * `confirmation` 对普通动作必须为空，对高风险动作必须与 actionKey 完全相同。核心先记录 requested
     * 审计，再调用插件，成功后记录 completed/queued 终态；插件抛错时由控制器统一返回脱敏 503，异常
     * 正文和插件结果不会进入审计或响应。
     *
     * @return array{status:'completed'|'queued',message:string,referenceId:?string}
     */
    public function execute(
        string $pluginKey,
        string $actionKey,
        ?string $confirmation,
        string $actorId,
        string $requestId,
    ): array {
        $hook = $this->registry->adminActions($pluginKey);
        $actions = $this->validatedActions($hook->adminActions());
        $action = null;
        foreach ($actions as $candidate) {
            if ($candidate['key'] === $actionKey) {
                $action = $candidate;
                break;
            }
        }
        if (!is_array($action)
            || ($action['confirmationRequired'] ? $confirmation !== $actionKey : $confirmation !== null)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_ADMIN_ACTION_INVALID');
        }

        $this->audit->record($actorId, 'php_resource_plugin.admin_action.request', 'php_resource_plugin', $pluginKey,
            'requested', $requestId, ['actionKey' => $actionKey]);
        $result = $this->validatedResult($hook->runAdminAction($actionKey, $actorId, $requestId));
        $this->audit->record($actorId, 'php_resource_plugin.admin_action.complete', 'php_resource_plugin', $pluginKey,
            $result['status'], $requestId, [
                'actionKey' => $actionKey,
                'hasReference' => $result['referenceId'] !== null,
            ]);
        $this->events->publish(
            PluginDomainEvent::PLUGIN_ADMIN_ACTION_COMPLETED,
            'php_resource_plugin',
            $pluginKey,
            'user',
            $actorId,
            ['actionKey' => $actionKey, 'status' => $result['status'], 'hasReference' => $result['referenceId'] !== null],
        );
        return $result;
    }

    /** @return list<array{key:string,title:string,description:string,confirmationRequired:bool}> */
    private function validatedActions(mixed $actions): array
    {
        if (!is_array($actions) || !array_is_list($actions) || $actions === [] || count($actions) > self::MAX_ACTIONS) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_ADMIN_ACTIONS_INVALID');
        }
        $validated = [];
        $keys = [];
        foreach ($actions as $action) {
            if (!is_array($action) || array_is_list($action)) {
                throw new PhpResourcePluginInvalid('PHP_PLUGIN_ADMIN_ACTIONS_INVALID');
            }
            $fields = array_keys($action);
            sort($fields);
            if ($fields !== ['confirmationRequired', 'description', 'key', 'title']
                || !is_string($action['key'] ?? null)
                || preg_match('/^[a-z][a-z0-9_-]{1,47}$/D', $action['key']) !== 1
                || isset($keys[$action['key']])
                || !$this->text($action['title'] ?? null, 1, 80)
                || !$this->text($action['description'] ?? null, 1, 240)
                || !is_bool($action['confirmationRequired'] ?? null)) {
                throw new PhpResourcePluginInvalid('PHP_PLUGIN_ADMIN_ACTIONS_INVALID');
            }
            $keys[$action['key']] = true;
            $validated[] = [
                'key' => $action['key'],
                'title' => $action['title'],
                'description' => $action['description'],
                'confirmationRequired' => $action['confirmationRequired'],
            ];
        }
        return $validated;
    }

    /** @return array{status:'completed'|'queued',message:string,referenceId:?string} */
    private function validatedResult(mixed $result): array
    {
        if (!is_array($result) || array_is_list($result)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_ADMIN_ACTION_RESULT_INVALID');
        }
        $fields = array_keys($result);
        sort($fields);
        $referenceId = $result['referenceId'] ?? null;
        if ($fields !== ['message', 'referenceId', 'status']
            || !in_array($result['status'] ?? null, ['completed', 'queued'], true)
            || !$this->text($result['message'] ?? null, 1, 240)
            || ($referenceId !== null && !$this->text($referenceId, 1, 160))) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_ADMIN_ACTION_RESULT_INVALID');
        }
        return ['status' => $result['status'], 'message' => $result['message'], 'referenceId' => $referenceId];
    }

    private function text(mixed $value, int $minimum, int $maximum): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') >= $minimum && mb_strlen($value, 'UTF-8') <= $maximum
            && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1;
    }
}
