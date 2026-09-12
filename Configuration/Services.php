<?php

declare(strict_types=1);

use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Core\Core\Environment;
use Webconsulting\Typo3AiChat\Testing\ScriptedProvider;

/**
 * The one thing Services.yaml cannot express: a service that must exist ONLY
 * where it is safe.
 *
 * {@see ScriptedProvider} is an LLM that says whatever a file tells it to say.
 * Reachable in production it would be a way to put words in the assistant's
 * mouth, so it is registered behind TWO independent conditions — a
 * non-production application context AND an explicit environment flag. Either
 * one alone is the kind of switch that gets left on by accident; both together
 * is a deliberate act.
 *
 * Services.yaml is loaded alongside this file, not instead of it, so the
 * ordinary wiring stays where it is readable.
 */
return static function (ContainerConfigurator $configurator, ContainerBuilder $container): void {
    $context = Environment::getContext();
    $permitted = getenv(ScriptedProvider::ENV_FLAG) === '1'
        && ($context->isDevelopment() || $context->isTesting());

    if (!$permitted) {
        return;
    }

    $configurator->services()
        ->set(ScriptedProvider::class)
        ->autowire()
        ->autoconfigure();

    // nr-llm builds provider adapters from an adapter-type map and offers
    // exactly one seam for extending it: the registry's $adapterOverrides
    // argument. A NEW key rather than a replaced built-in means a provider
    // record has to name "scripted" explicitly to reach it.
    if ($container->hasDefinition(ProviderAdapterRegistry::class)) {
        $container->getDefinition(ProviderAdapterRegistry::class)
            ->setArgument('$adapterOverrides', [ScriptedProvider::ADAPTER_TYPE => ScriptedProvider::class]);
    }
};
