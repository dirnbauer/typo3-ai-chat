<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Testing;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use RuntimeException;

/**
 * An LLM that says exactly what a test told it to say.
 *
 * Testing an agent loop needs a model that can be made to call a tool, then be
 * asked again, then answer — deterministically, in the right order, with no
 * network. Recording real provider traffic would freeze one provider's exact
 * wire dialect into the fixtures; a mock of the provider interface would not
 * exercise the loop's own normalisation. A scripted provider sits between: a
 * real adapter, driven by a queue.
 *
 * The queue is a FILE, not a static property, because the turn under test runs
 * in the same process but through the DI container — and a functional test
 * rebuilds that container, taking any in-memory state with it.
 *
 * NOT PRODUCTION CODE. It ships in Classes/ so the container can autoload it,
 * and is registered only when BOTH the application context is
 * Development/Testing AND `WEBCONSULTING_AI_CHAT_SCRIPTED_PROVIDER=1` is set
 * (see Configuration/Services.php). Two independent conditions, because one of
 * them alone is the kind of thing that gets turned on by accident.
 */
final class ScriptedProvider extends AbstractProvider implements ToolCapableInterface
{
    public const ADAPTER_TYPE = 'scripted';

    public const ENV_FLAG = 'WEBCONSULTING_AI_CHAT_SCRIPTED_PROVIDER';

    public const ENV_SCRIPT = 'WEBCONSULTING_AI_CHAT_SCRIPT_FILE';

    public function getName(): string
    {
        return 'Scripted (testing)';
    }

    public function getIdentifier(): string
    {
        return self::ADAPTER_TYPE;
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://scripted.invalid';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function supportsTools(): bool
    {
        return true;
    }

    public function supportsFeature(string|\Netresearch\NrLlm\Domain\Enum\ModelCapability $feature): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableModels(): array
    {
        return ['scripted-1' => 'Scripted 1'];
    }

    public function getDefaultModel(): string
    {
        return 'scripted-1';
    }

    public function testConnection(): array
    {
        return ['success' => true, 'message' => 'Scripted provider needs no connection.'];
    }

    /**
     * @param list<mixed>          $messages
     * @param array<string, mixed> $options
     */
    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        return $this->nextResponse();
    }

    /**
     * @param list<mixed>          $messages
     * @param list<mixed>          $tools
     * @param array<string, mixed> $options
     */
    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        return $this->nextResponse();
    }

    /**
     * @param string|array<int, string> $input
     * @param array<string, mixed>      $options
     */
    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        return new EmbeddingResponse([], $this->getDefaultModel(), new UsageStatistics(0, 0, 0));
    }

    // --------------------------------------------------------------- scripting

    /**
     * Queue what the model will say, in order.
     *
     * Each entry is either `['content' => '…']` or
     * `['toolCalls' => [['id' => …, 'name' => …, 'arguments' => [...]], …]]`.
     *
     * @param list<array<string, mixed>> $responses
     */
    public static function script(array $responses): void
    {
        file_put_contents(self::scriptFile(), json_encode($responses, JSON_THROW_ON_ERROR));
    }

    public static function reset(): void
    {
        $file = self::scriptFile();
        if (is_file($file)) {
            unlink($file);
        }
    }

    public static function scriptFile(): string
    {
        $configured = getenv(self::ENV_SCRIPT);

        return is_string($configured) && $configured !== ''
            ? $configured
            : sys_get_temp_dir() . '/webconsulting-ai-chat-script.json';
    }

    /**
     * Pop the next scripted response and turn it into what the loop expects.
     *
     * Running out is an ERROR, not an empty answer: a loop that took one more
     * round than the test scripted has changed behaviour, and silently
     * returning "" would let that change pass as a passing test.
     */
    private function nextResponse(): CompletionResponse
    {
        $file = self::scriptFile();
        $raw = is_file($file) ? (string)file_get_contents($file) : '[]';
        $queue = json_decode($raw, true);
        if (!is_array($queue) || $queue === []) {
            throw new RuntimeException(
                'The scripted provider was asked for a response the test did not script.',
                1794000401,
            );
        }

        /** @var array<string, mixed> $next */
        $next = array_shift($queue);
        file_put_contents($file, json_encode(array_values($queue), JSON_THROW_ON_ERROR));

        $content = is_string($next['content'] ?? null) ? $next['content'] : '';
        $toolCalls = $this->toolCalls($next);

        return new CompletionResponse(
            content: $content,
            model: $this->getDefaultModel(),
            usage: new UsageStatistics(11, 7, 18),
            finishReason: $toolCalls === null ? 'stop' : 'tool_calls',
            provider: self::ADAPTER_TYPE,
            toolCalls: $toolCalls,
        );
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return list<ToolCall>|null
     */
    private function toolCalls(array $response): ?array
    {
        $raw = $response['toolCalls'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $calls = [];
        foreach ($raw as $index => $call) {
            if (!is_array($call)) {
                continue;
            }
            $name = is_string($call['name'] ?? null) ? $call['name'] : '';
            if ($name === '') {
                continue;
            }
            $id = is_string($call['id'] ?? null) && $call['id'] !== ''
                ? $call['id']
                : 'call-' . ($index + 1);
            $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];

            $calls[] = ToolCall::function($id, $name, $arguments);
        }

        return $calls === [] ? null : $calls;
    }
}
