<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\BuildStep;
use PHPat\Test\PHPat;

final class LayerDependencyTest
{
    private const CONTROLLER_NAMESPACE = 'Netresearch\NrMcpAgent\Controller';
    private const MCP_NAMESPACE = 'Netresearch\NrMcpAgent\Mcp';

    public function testDomainDoesNotDependOnInfrastructure(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrMcpAgent\Domain'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::CONTROLLER_NAMESPACE),
                Selector::inNamespace('Netresearch\NrMcpAgent\Command'),
                Selector::inNamespace(self::MCP_NAMESPACE),
            )
            ->because('Domain layer must not depend on infrastructure (Controller, Command, Mcp)');
    }

    public function testServicesDoNotAccessDatabaseDirectly(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrMcpAgent\Service'))
            ->shouldNotDependOn()
            ->classes(Selector::classname('TYPO3\CMS\Core\Database\ConnectionPool'))
            ->because('Services must use repositories instead of accessing the database directly');
    }

    public function testControllerDoesNotExecuteProcesses(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::CONTROLLER_NAMESPACE))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace(self::MCP_NAMESPACE))
            ->because('Controllers must not depend on MCP layer directly');
    }

    public function testHookDoesNotDependOnController(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrMcpAgent\Hook'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::CONTROLLER_NAMESPACE),
                Selector::inNamespace(self::MCP_NAMESPACE),
                Selector::inNamespace('Netresearch\NrMcpAgent\Service'),
            )
            ->because('Hook layer must not depend on Controller, Mcp, or Service layers');
    }
}
