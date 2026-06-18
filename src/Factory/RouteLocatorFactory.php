<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Factory;

use Componenta\Config\Config;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Factory\RouteLocatorFactory as BaseRouteLocatorFactory;
use Componenta\Http\Router\Locator\RouteLocator;
use Psr\Container\ContainerInterface;

final readonly class RouteLocatorFactory
{
    public function __invoke(ContainerInterface $container): RouteLocator|AttributeRouteLocator
    {
        /** @var Config $config */
        $config = $container->get(ConfigKey::CONFIG);

        if ($config->environment->match('APP_ENV', 'production')) {
            return (new BaseRouteLocatorFactory())($container);
        }

        return $container->get(AttributeRouteLocator::class);
    }
}
