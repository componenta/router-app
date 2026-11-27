<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Factory;

use Componenta\App\Build\PreparedBuild;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\Http\Router\App\Build\RouteBuilder;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\App\Locator\PreparedRouteLocator;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use RuntimeException;

final readonly class RouteLocatorFactory
{
    public function __invoke(ContainerValue $container): RouteLocatorInterface
    {
        $source = static fn (): AttributeRouteLocator => $container->get(AttributeRouteLocator::class, AttributeRouteLocator::class);
        if ($container->has(AppConfigKey::PREPARED_BUILD)) {
            $prepared = $container->get(AppConfigKey::PREPARED_BUILD, PreparedBuild::class);
            if ($prepared->isRequired(RouteBuilder::ARTIFACT_ID)) {
                // Explicit deployment policy is independent of APP_ENV.
                if ($container->config->environment->bool('APP_DISCOVERY_SOURCE', false)
                    || !$container->config->bool(ConfigKey::COMPILED_PIPELINE, true)) {
                    throw new RuntimeException('Source routing conflicts with required build artifact "' . RouteBuilder::ARTIFACT_ID . '".');
                }
                return new PreparedRouteLocator($prepared, $source);
            }

            if (!$container->config->environment->bool('APP_DISCOVERY_SOURCE', false)
                && $container->config->environment->match('APP_ENV', 'production')
                && $container->config->bool(ConfigKey::COMPILED_PIPELINE, true)) {
                return new PreparedRouteLocator($prepared, $source);
            }
        }
        return $source();
    }
}
