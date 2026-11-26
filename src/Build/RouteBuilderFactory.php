<?php
declare(strict_types=1);
namespace Componenta\Http\Router\App\Build;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\App\Factory\RouteLocatorFactory;

final readonly class RouteBuilderFactory
{
    public function __invoke(ContainerValue $container): RouteBuilder
    {
        $compiler = $container->get(CompilerInterface::class, CompilerInterface::class);
        return new RouteBuilder(
            $container->get(AttributeRouteLocator::class, AttributeRouteLocator::class),
            RouteLocatorFactory::cacheFile($container),
            new RouteCacheGenerator($compiler),
            $container->get(AppConfigKey::BUILD_GENERATION),
        );
    }
}
