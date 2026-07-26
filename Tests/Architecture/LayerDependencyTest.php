<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\BuildStep;
use PHPat\Test\PHPat;

final class LayerDependencyTest
{
    private const CONTROLLER_NAMESPACE = 'Webconsulting\Typo3AiChat\Controller';
    private const MCP_NAMESPACE = 'Webconsulting\Typo3AiChat\Mcp';

    public function testDomainDoesNotDependOnInfrastructure(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Webconsulting\Typo3AiChat\Domain'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::CONTROLLER_NAMESPACE),
                Selector::inNamespace('Webconsulting\Typo3AiChat\Command'),
                Selector::inNamespace(self::MCP_NAMESPACE),
            )
            ->because('Domain layer must not depend on infrastructure (Controller, Command, Mcp)');
    }

    public function testServicesDoNotAccessDatabaseDirectly(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Webconsulting\Typo3AiChat\Service'))
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
            ->classes(Selector::inNamespace('Webconsulting\Typo3AiChat\Hook'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::CONTROLLER_NAMESPACE),
                Selector::inNamespace(self::MCP_NAMESPACE),
                Selector::inNamespace('Webconsulting\Typo3AiChat\Service'),
            )
            ->because('Hook layer must not depend on Controller, Mcp, or Service layers');
    }
}
