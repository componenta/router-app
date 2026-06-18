<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Factory;

use Componenta\Config\Config;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Locator\RouteLocator;
use Componenta\Stdlib\PathResolverInterface;
use Psr\Container\ContainerInterface;

final readonly class AttributeRouteLocatorFactory
{
    public function __invoke(ContainerInterface $container): AttributeRouteLocator
    {
        /** @var Config $config */
        $config = $container->get(ConfigKey::CONFIG);

        $paths = $container->get(PathResolverInterface::class);
        $routesFile = $paths->resolve($config->get(ConfigKey::ROUTES_FILE));

        return new AttributeRouteLocator(new RouteLocator(
            $routesFile,
            $container->get(CompilerInterface::class),
        ));
    }
}
