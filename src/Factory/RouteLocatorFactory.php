<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Factory;

use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\App\Locator\PreparedRouteLocator;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\Factory\RouteLocatorFactory as BaseRouteLocatorFactory;
use Componenta\Stdlib\PathResolverInterface;

final readonly class RouteLocatorFactory
{
    public function __invoke(ContainerValue $container): RouteLocatorInterface
    {
        $source = static fn (): AttributeRouteLocator => $container->get(AttributeRouteLocator::class, AttributeRouteLocator::class);
        if (!$container->config->environment->bool('APP_DISCOVERY_SOURCE', false)
            && $container->config->environment->match('APP_ENV', 'production')
            && $container->config->bool(ConfigKey::COMPILED_PIPELINE, true)
            && $container->has(AppConfigKey::DISCOVERY_GENERATION)) {
            $generation = $container->get(AppConfigKey::DISCOVERY_GENERATION);
            if (is_string($generation) && preg_match('/\A[0-9a-f]{32}\z/', $generation) === 1) {
                return new PreparedRouteLocator(self::cacheFile($container), $generation, $source);
            }
        }
        return $source();
    }

    /** The router must never publish or clean an App-owned discovery or boot map. */
    public static function cacheFile(ContainerValue $container): string
    {
        $paths = $container->get(PathResolverInterface::class, PathResolverInterface::class);
        return BaseRouteLocatorFactory::cacheFile($container, [
            $paths->resolve(AppConfigKey::DISCOVERY_MAP_FILE),
            $paths->resolve(AppConfigKey::BOOT_MAP_FILE),
        ]);
    }
}
