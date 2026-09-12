<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The one sanctioned place this extension reads `$GLOBALS['BE_USER']`.
 *
 * Everything downstream — a run request, an approval, a tool call — carries an
 * explicit {@see AiActorContext} instead of re-reading the ambient user, which
 * is nr-llm's rule (ADR-083) and the only way a decision stays auditable. The
 * HTTP boundary is where the ambient user genuinely IS the caller, so the read
 * happens here and nowhere else.
 */
final readonly class BackendUserContext
{
    public function user(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }

    public function uid(): int
    {
        $user = $this->user();
        // BackendUserAuthentication::$user is untyped and may be null before a
        // session is fully loaded (CLI, testing), so guard with is_array().
        $record = $user !== null && is_array($user->user) ? $user->user : [];
        $uid = $record['uid'] ?? 0;

        return is_numeric($uid) ? (int)$uid : 0;
    }

    public function isAdmin(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return list<int>
     */
    public function groupIds(): array
    {
        $user = $this->user();
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(mixed $group): int => is_numeric($group) ? (int)$group : 0, $user->userGroupsUID),
            static fn(int $group): bool => $group > 0,
        ));
    }

    /**
     * The full actor for the current request: uid, admin flag, group uids and
     * nr-llm capability grants, frozen here.
     *
     * Frozen, not referenced: the grants are read from the live user's group
     * permissions at this moment, so a revoked grant stops working with the
     * next request while an in-flight run keeps the identity it started with.
     */
    public function actor(): AiActorContext
    {
        $user = $this->user();
        $uid = $this->uid();
        if ($user === null || $uid === 0) {
            return AiActorContext::anonymous();
        }

        $grants = array_values(array_filter(
            BackendUserGrant::cases(),
            static fn(BackendUserGrant $grant): bool => (bool)$user->check('custom_options', $grant->permissionValue()),
        ));

        return AiActorContext::backendUser($uid, $user->isAdmin(), $this->groupIds(), $grants);
    }

    /**
     * Whether the current user may use the chat at all.
     *
     * An empty allow-list means everybody; administrators are never locked out,
     * consistently with every other surface in this extension.
     *
     * @param list<int> $allowedGroupIds
     */
    public function mayUseChat(array $allowedGroupIds): bool
    {
        if ($this->user() === null || $this->uid() === 0) {
            return false;
        }
        if ($allowedGroupIds === [] || $this->isAdmin()) {
            return true;
        }

        return array_intersect($allowedGroupIds, $this->groupIds()) !== [];
    }
}
