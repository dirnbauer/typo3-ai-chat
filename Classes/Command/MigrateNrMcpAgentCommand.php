<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Copies nr-mcp-agent data before the original extension is removed.
 *
 * The command is intentionally idempotent: existing target UIDs are preserved
 * and never overwritten. It lets installations verify the replacement first,
 * run the migration repeatedly, and only then uninstall nr_mcp_agent.
 */
#[AsCommand(
    name: 'ai-chat:migrate-nr-mcp-agent',
    description: 'Migrate conversations and MCP server records from Netresearch nr-mcp-agent.',
)]
final class MigrateNrMcpAgentCommand extends Command
{
    private const SOURCE_CONVERSATIONS = 'tx_nrmcpagent_conversation';
    private const TARGET_CONVERSATIONS = 'tx_webconsultingaichat_conversation';
    private const SOURCE_SERVERS = 'tx_nrmcpagent_mcp_server';
    private const TARGET_SERVERS = 'tx_webconsultingaichat_mcp_server';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $conversationConnection = $this->connectionPool->getConnectionForTable(self::TARGET_CONVERSATIONS);
        $schema = $conversationConnection->createSchemaManager();
        if (!$schema->tablesExist([self::TARGET_CONVERSATIONS])) {
            $output->writeln('<error>The Webconsulting AI Chat schema is missing. Run database:updateschema first.</error>');
            return Command::FAILURE;
        }
        if (!$schema->tablesExist([self::SOURCE_CONVERSATIONS])) {
            $output->writeln('<info>No nr-mcp-agent tables found; nothing to migrate.</info>');
            return Command::SUCCESS;
        }

        $conversationColumns = [
            'uid', 'pid', 'deleted', 'be_user', 'title', 'messages', 'message_count',
            'status', 'current_request_id', 'system_prompt', 'archived', 'pinned',
            'error_message', 'tstamp', 'crdate',
        ];
        $conversations = $this->copyRows(
            $conversationConnection,
            self::SOURCE_CONVERSATIONS,
            self::TARGET_CONVERSATIONS,
            $conversationColumns,
        );

        $servers = 0;
        if ($schema->tablesExist([self::SOURCE_SERVERS, self::TARGET_SERVERS])) {
            $serverColumns = [
                'uid', 'pid', 'deleted', 'hidden', 'sorting', 'name', 'server_key',
                'transport', 'command', 'arguments', 'url', 'auth_token',
                'connection_status', 'connection_checked', 'connection_error',
            ];
            $servers = $this->copyRows(
                $conversationConnection,
                self::SOURCE_SERVERS,
                self::TARGET_SERVERS,
                $serverColumns,
            );
        }

        $output->writeln(sprintf(
            '<info>Migrated %d conversation(s) and %d MCP server record(s).</info>',
            $conversations,
            $servers,
        ));
        $output->writeln('<comment>Thank you, Netresearch, for the original nr-mcp-agent data model and extension.</comment>');

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $columns
     */
    private function copyRows(Connection $connection, string $source, string $target, array $columns): int
    {
        $rows = $connection->createQueryBuilder()
            ->select(...$columns)
            ->from($source)
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $inserted = 0;
        foreach ($rows as $row) {
            $uid = is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0;
            if ($uid <= 0 || $this->exists($connection, $target, $uid)) {
                continue;
            }

            $types = array_fill_keys($columns, \Doctrine\DBAL\ParameterType::STRING);
            foreach (['uid', 'pid', 'deleted', 'be_user', 'message_count', 'archived', 'pinned', 'tstamp', 'crdate', 'hidden', 'sorting', 'connection_checked'] as $integerColumn) {
                if (array_key_exists($integerColumn, $types)) {
                    $types[$integerColumn] = \Doctrine\DBAL\ParameterType::INTEGER;
                }
            }
            $connection->insert($target, array_intersect_key($row, array_flip($columns)), $types);
            ++$inserted;
        }

        return $inserted;
    }

    private function exists(Connection $connection, string $table, int $uid): bool
    {
        $count = $connection->createQueryBuilder()
            ->count('uid')
            ->from($table)
            ->where('uid = :uid')
            ->setParameter('uid', $uid, \Doctrine\DBAL\ParameterType::INTEGER)
            ->executeQuery()
            ->fetchOne();

        if (is_int($count)) {
            return $count > 0;
        }

        return is_string($count) && ctype_digit($count) && (int) $count > 0;
    }
}
