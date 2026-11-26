<?php
declare(strict_types=1);
namespace Componenta\Http\Router\App\Build;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ContainerValue;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Factory\RouteLocatorFactory;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Locator\RouteLocator;
use Componenta\Stdlib\PathResolverInterface;

final readonly class RouteBuilderFactory
{
    public function __invoke(ContainerValue $container): RouteBuilder
    {
        $paths = $container->get(PathResolverInterface::class, PathResolverInterface::class);
        $compiler = $container->get(CompilerInterface::class, CompilerInterface::class);
        return new RouteBuilder(
            new AttributeRouteLocator(
                new RouteLocator($paths->resolve($container->config->string(ConfigKey::ROUTES_FILE)), $compiler, useCache: false),
                $container->get(AppConfigKey::DISCOVERY_SOURCE, ClassIteratorInterface::class),
            ),
            RouteLocatorFactory::cacheFile($container),
            new RouteCacheGenerator($compiler),
            $container->get(AppConfigKey::BUILD_GENERATION),
        );
    }
}
