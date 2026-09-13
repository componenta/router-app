<?php
declare(strict_types=1);
namespace Componenta\Http\Router\App\Factory;
use Componenta\Config\ContainerValue;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\Factory\RouteLocatorFactory as BaseRouteLocatorFactory;
use Componenta\Http\Router\Locator\CachedRouteLocator;

final readonly class RouteLocatorFactory
{
    public function __invoke(ContainerValue $container): RouteLocatorInterface
    {
        $source = static fn (): AttributeRouteLocator => $container->get(AttributeRouteLocator::class, AttributeRouteLocator::class);
        if ($container->config->environment->match('APP_ENV', 'production')
            && $container->config->bool(ConfigKey::COMPILED_PIPELINE, true)) {
            return new CachedRouteLocator(BaseRouteLocatorFactory::cacheFile($container), $source);
        }
        return $source();
    }
}
