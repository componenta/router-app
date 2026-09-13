<?php
declare(strict_types=1);
namespace Componenta\Http\Router\App\Factory;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ContainerValue;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Locator\RouteLocator;
use Componenta\Stdlib\PathResolverInterface;

final readonly class AttributeRouteLocatorFactory
{
    public function __invoke(ContainerValue $container): AttributeRouteLocator
    {
        $paths = $container->get(PathResolverInterface::class, PathResolverInterface::class);
        return new AttributeRouteLocator(
            new RouteLocator($paths->resolve($container->config->string(ConfigKey::ROUTES_FILE)),
                $container->get(CompilerInterface::class, CompilerInterface::class), useCache: false),
            $container->has(AppConfigKey::DISCOVERY_SOURCE)
                ? $container->get(AppConfigKey::DISCOVERY_SOURCE, ClassIteratorInterface::class)
                : new ClassIterator([]),
        );
    }
}
