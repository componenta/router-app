<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Factory;

use Componenta\App\Build\PreparedBuild;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\App\Locator\PreparedRouteLocator;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\RouteLocatorInterface;

final readonly class RouteLocatorFactory
{
    public function __invoke(ContainerValue $container): RouteLocatorInterface
    {
        $source = static fn (): AttributeRouteLocator => $container->get(AttributeRouteLocator::class, AttributeRouteLocator::class);
        if (!$container->config->environment->bool('APP_DISCOVERY_SOURCE', false)
            && $container->config->environment->match('APP_ENV', 'production')
            && $container->config->bool(ConfigKey::COMPILED_PIPELINE, true)
            && $container->has(AppConfigKey::PREPARED_BUILD)) {
            return new PreparedRouteLocator($container->get(AppConfigKey::PREPARED_BUILD, PreparedBuild::class), $source);
        }
        return $source();
    }
}
