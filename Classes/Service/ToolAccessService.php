<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;
use Webconsulting\Typo3AiChat\Tool\McpCatalogTool;

/**
 * Which tools this turn may offer the model.
 *
 * Three gates, intersected — and the order they are written in is the order of
 * authority, coarsest first:
 *
 * 1. **nr-llm's own tool policy.** What an administrator enabled globally in
 *    the Tools module. The runtime enforces this again at call time (nr-llm
 *    ADR-093), so nothing here can widen it; computing it up front only stops
 *    the model from being told about a tool it would then be refused.
 * 2. **Extension configuration.** `allowedGroups` decides who may use the chat
 *    at all; a user outside it gets no tools because they get no chat.
 * 3. **User TSconfig.** `tx_webconsultingaichat.tools.allow` and `.deny` let an
 *    integrator narrow per user or per group — an editor who may read but never
 *    write, say. `deny` wins over `allow`, because a narrowing rule that can be
 *    cancelled by a broader one further down is not a narrowing rule.
 *
 * An empty result is a legitimate answer: a chat with no tools still answers
 * questions, it just cannot touch the installation.
 */
final readonly class ToolAccessService
{
    private const TSCONFIG_PATH = 'tx_webconsultingaichat.';

    public function __construct(
        private ToolAvailabilityServiceInterface $toolAvailability,
        private ExtensionConfiguration $config,
        private BackendUserContext $backendUser,
    ) {}

    /**
     * The tool names this user may have offered.
     *
     * Always an explicit list, never nr-llm's "null means the global set"
     * shorthand: the status endpoint has to show the user which tools this
     * conversation can reach, and it cannot render a shorthand. Starting from
     * the global set and narrowing it gives the same answer with a name
     * attached to every entry.
     *
     * @return list<string>
     */
    public function allowedToolNames(): array
    {
        if (!$this->backendUser->mayUseChat($this->config->getAllowedGroupIds())) {
            return [];
        }

        $enabled = $this->toolAvailability->enabledNames();

        [$allow, $deny] = $this->tsConfigRules();

        if ($allow !== null) {
            $enabled = array_values(array_filter(
                $enabled,
                static fn(string $name): bool => in_array($name, $allow, true),
            ));
        }

        if ($deny !== []) {
            $enabled = array_values(array_filter(
                $enabled,
                static fn(string $name): bool => !in_array($name, $deny, true),
            ));
        }

        return $enabled;
    }

    /**
     * The MCP tools among the allowed set, as MCP names — what the status
     * endpoint lists so a user can see which of their installation's tools this
     * conversation can reach.
     *
     * @return list<string>
     */
    public function allowedMcpToolNames(): array
    {
        $names = [];
        foreach ($this->allowedToolNames() as $toolName) {
            $mcpName = McpCatalogTool::mcpName($toolName);
            if ($mcpName !== null) {
                $names[] = $mcpName;
            }
        }

        return $names;
    }

    /**
     * @return array{0: list<string>|null, 1: list<string>}
     */
    private function tsConfigRules(): array
    {
        $user = $this->backendUser->user();
        if (!$user instanceof BackendUserAuthentication) {
            return [null, []];
        }

        $tsConfig = $user->getTSConfig();
        $tools = $tsConfig[self::TSCONFIG_PATH . 'tools.'] ?? null;
        if (!is_array($tools)) {
            return [null, []];
        }

        $allowRaw = $tools['allow'] ?? null;
        $denyRaw = $tools['deny'] ?? null;

        // An ABSENT allow list and an EMPTY one are different answers: absent
        // means "do not narrow", empty means "allow nothing". Collapsing them
        // would make `allow =` silently permissive, which is the wrong
        // direction for a security setting.
        $allow = is_string($allowRaw) ? $this->splitList($allowRaw) : null;
        $deny = is_string($denyRaw) ? $this->splitList($denyRaw) : [];

        return [$allow, $deny];
    }

    /**
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        $names = [];
        foreach (explode(',', $value) as $candidate) {
            $name = trim($candidate);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
