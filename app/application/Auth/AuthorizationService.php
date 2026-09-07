<?php

declare(strict_types=1);

namespace app\application\Auth;

use support\Request;

/**
 * Enforces authenticated global capabilities at application-service entry points.
 *
 * Frontend route guards and hidden navigation are usability aids only. Every protected controller
 * must call this service before reading or mutating managed data. Object and library scope checks
 * remain additional mandatory conditions and cannot be replaced by a matching global capability.
 */
final readonly class AuthorizationService
{
    public function __construct(
        private SessionService $sessions = new SessionService(),
        private PersonalAccessTokenAuthenticator $tokens = new PersonalAccessTokenAuthenticator(),
        private AppAccessTokenAuthenticator $appTokens = new AppAccessTokenAuthenticator(),
    ) {
    }

    /**
     * 解析当前请求身份；显式 Bearer 头绝不回退到 Cookie，防止无效令牌意外借用浏览器会话权限。
     *
     * @return array<string,mixed>|null
     */
    public function currentActor(Request $request): ?array
    {
        $authorization = $request->header('authorization');
        if (is_string($authorization) && $authorization !== '') {
            return str_starts_with($authorization, 'Bearer velin_app_at_')
                ? $this->appTokens->authenticate($request)
                : $this->tokens->authenticate($request);
        }
        return $this->sessions->currentUser($request);
    }

    /**
     * Returns the current sanitized actor after proving the required global capability.
     *
     * @return array<string, mixed> Current user snapshot owned by SessionService.
     * @throws AuthenticationRequired No valid session exists.
     * @throws AuthorizationDenied The current role snapshot lacks the capability.
     */
    public function requireCapability(Request $request, string $capability): array
    {
        $actor = $this->currentActor($request);
        if ($actor === null) {
            throw new AuthenticationRequired('Authentication is required.');
        }
        if (($actor['librarySetupRequired'] ?? false) === true) {
            throw new LibrarySetupRequired('A default music library must be configured first.');
        }

        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array($capability, $capabilities, true)) {
            throw new AuthorizationDenied('Required capability is missing.');
        }

        return $actor;
    }

    /**
     * Returns the actor after proving at least one capability from a server-owned allowlist.
     *
     * This is used by aggregate pages whose adapters have different permissions. It must not replace
     * each adapter's object and capability checks; it only establishes that the aggregate itself is
     * reachable. An empty requirement is rejected to prevent accidental authenticated-only access.
     *
     * @param list<string> $capabilities
     */
    public function requireAnyCapability(Request $request, array $capabilities): array
    {
        $actor = $this->currentActor($request);
        if ($actor === null) throw new AuthenticationRequired('Authentication is required.');
        if (($actor['librarySetupRequired'] ?? false) === true) {
            throw new LibrarySetupRequired('A default music library must be configured first.');
        }
        if ($capabilities === []) throw new AuthorizationDenied('No capability was configured.');
        $owned = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        foreach ($capabilities as $capability) {
            if (in_array($capability, $owned, true)) return $actor;
        }
        throw new AuthorizationDenied('Required capability is missing.');
    }
}
