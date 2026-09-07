<?php

declare(strict_types=1);

namespace app\application\Auth;

use Workerman\Protocols\Http\Session;
/**
 * 管理登录失败后绑定匿名 Session 的轻量验证码。
 *
 * 首次密码错误前不创建挑战；挑战只保存在当前 Web Session，答案以 SHA-256 摘要保存并在成功或过期
 * 后清理。验证码校验失败会轮换题目，调用方必须在密码校验前调用 verify，避免攻击者借验证码接口放大
 * Argon2 工作量。该服务不写数据库、不记录答案，也不代表账号是否存在。
 */
final class LoginCaptchaService
{
    private const REQUIRED_KEY = 'login.captcha.required';
    private const QUESTION_KEY = 'login.captcha.question';
    private const ANSWER_KEY = 'login.captcha.answer';
    private const EXPIRES_KEY = 'login.captcha.expires';
    private const TTL_SECONDS = 600;

    /** @return array{required:bool,question?:string,expiresAt?:int} */
    public function challenge(Session $session): array
    {
        if (!$this->isRequired($session)) return ['required' => false];
        return [
            'required' => true,
            'question' => (string) $session->get(self::QUESTION_KEY),
            'expiresAt' => (int) $session->get(self::EXPIRES_KEY),
        ];
    }

    public function isRequired(Session $session): bool
    {
        $expires = (int) $session->get(self::EXPIRES_KEY);
        if ($session->get(self::REQUIRED_KEY) !== true || $expires <= time()) {
            if ($expires > 0) $this->clear($session);
            return false;
        }
        return true;
    }

    /** 标记一次密码失败并创建首个挑战；已有挑战不会因重复错误而延长有效期。 */
    public function registerFailure(Session $session): void
    {
        if ($this->isRequired($session)) return;
        $left = random_int(1, 20);
        $right = random_int(1, 20);
        $answer = (string) ($left + $right);
        $session->set(self::REQUIRED_KEY, true);
        $session->set(self::QUESTION_KEY, "{$left} + {$right} = ?");
        $session->set(self::ANSWER_KEY, hash('sha256', $answer));
        $session->set(self::EXPIRES_KEY, time() + self::TTL_SECONDS);
    }

    /** 验证当前答案并消费挑战；错误答案会立即生成新题。 */
    public function verify(Session $session, string $answer): bool
    {
        $wasRequired = $session->get(self::REQUIRED_KEY) === true;
        if (!$this->isRequired($session)) {
            if (!$wasRequired) return true;
            $this->registerFailure($session);
            return false;
        }
        $expected = (string) $session->get(self::ANSWER_KEY);
        $valid = $answer !== '' && hash_equals($expected, hash('sha256', trim($answer)));
        if (!$valid) {
            $this->clear($session);
            $this->registerFailure($session);
            return false;
        }
        return true;
    }

    public function clear(Session $session): void
    {
        foreach ([self::REQUIRED_KEY, self::QUESTION_KEY, self::ANSWER_KEY, self::EXPIRES_KEY] as $key) {
            $session->delete($key);
        }
    }
}
