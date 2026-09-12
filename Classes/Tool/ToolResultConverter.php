<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tool;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Netresearch\NrLlm\Domain\Enum\ArtifactType;
use Netresearch\NrLlm\Domain\ValueObject\ToolArtifact;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;

/**
 * Turns an MCP ``CallToolResult`` into the nr-llm ``ToolResult`` the agent loop
 * understands.
 *
 * The two formats differ in exactly the way that matters for egress. MCP has
 * one ``content`` list that a client renders however it likes; nr-llm splits
 * the result in two, and the split is a security boundary (nr-llm ADR-111):
 * ``content`` is the ONLY half that crosses the provider wire, while
 * ``artifacts`` reach the backend DOM and the audit stream and never the model.
 *
 * So the mapping is:
 *
 * - every ``TextContent`` is joined into the provider-facing text;
 * - other content kinds (image, audio, embedded resource) are replaced by a
 *   short marker — the model gets told something was there rather than a
 *   base64 blob it cannot read and the operator pays for;
 * - ``isError`` produces an error result, which by nr-llm's own construction
 *   carries no artifacts at all — a failed call must not leak a half-built
 *   structure;
 * - ``structuredContent`` becomes a run-only artifact: a TABLE when the
 *   structure is a list of uniform rows, a TEXT artifact carrying the JSON
 *   otherwise.
 */
final readonly class ToolResultConverter
{
    /**
     * Uniform-row detection stops here: beyond this many rows a table view is
     * not what an operator wants to read anyway, and the JSON artifact says
     * everything.
     */
    private const MAX_TABLE_ROWS = 200;

    public function convert(CallToolResult $result, string $toolName): ToolResult
    {
        $text = $this->flattenContent($result);

        if ($result->isError === true) {
            return ToolResult::error($text === '' ? sprintf('Tool "%s" reported an error without a message.', $toolName) : $text);
        }

        $artifact = $this->artifact($result, $toolName);
        if ($artifact === null) {
            return ToolResult::text($text);
        }

        return ToolResult::text($text, $artifact);
    }

    private function flattenContent(CallToolResult $result): string
    {
        $parts = [];
        foreach ($result->content as $item) {
            if ($item instanceof TextContent) {
                $parts[] = $item->text;
                continue;
            }

            $parts[] = sprintf('[%s content omitted]', $this->contentType($item));
        }

        return trim(implode("\n", $parts));
    }

    private function contentType(mixed $item): string
    {
        if (is_object($item) && property_exists($item, 'type') && is_string($item->type)) {
            return $item->type;
        }

        return 'non-text';
    }

    private function artifact(CallToolResult $result, string $toolName): ?ToolArtifact
    {
        $structured = $result->structuredContent;
        if (!is_array($structured) || $structured === []) {
            return null;
        }

        $table = $this->asTable($structured);
        if ($table !== null) {
            return new ToolArtifact(ArtifactType::TABLE, $toolName, $table);
        }

        $json = json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new ToolArtifact(ArtifactType::TEXT, $toolName, ['text' => $json !== false ? $json : '']);
    }

    /**
     * A list of associative rows that all share the same keys renders as a
     * table. Anything else — a nested object, a ragged list, a list of scalars —
     * does not, and falls through to the JSON artifact.
     *
     * @param array<array-key, mixed> $structured
     *
     * @return array{columns: list<string>, rows: list<list<string>>}|null
     */
    private function asTable(array $structured): ?array
    {
        if (!array_is_list($structured) || count($structured) > self::MAX_TABLE_ROWS) {
            return null;
        }

        $columns = null;
        $rows = [];
        foreach ($structured as $row) {
            if (!is_array($row) || $row === [] || array_is_list($row)) {
                return null;
            }

            $keys = array_map(strval(...), array_keys($row));
            if ($columns === null) {
                $columns = $keys;
            } elseif ($columns !== $keys) {
                return null;
            }

            $cells = [];
            foreach ($row as $value) {
                $cells[] = $this->scalarise($value);
            }
            $rows[] = $cells;
        }

        if ($columns === null || $columns === []) {
            return null;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    private function scalarise(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if ($value === null) {
            return '';
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json !== false ? $json : '';
    }
}
