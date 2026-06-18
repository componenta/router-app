<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App;

use Componenta\App\Console\ConfigKey as ConsoleConfigKey;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
use Componenta\ClassFinder\Compile\ConfigKey as CompileConfigKey;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Http\Router\App\Console\RouterListCommand;
use Componenta\Http\Middleware\ConfigKey as MiddlewareConfigKey;
use Componenta\Http\Router\App\Boot\RoutingBootloader;
use Componenta\Http\Router\App\Compile\RouteCacheCompiler;
use Componenta\Http\Router\App\Factory\AttributeRouteLocatorFactory;
use Componenta\Http\Router\App\Factory\InterceptedRouteHandlerResolverFactory;
use Componenta\Http\Router\App\Factory\RouteLocatorFactory;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\App\Resolver\InterceptedRouteHandlerResolver;
use Componenta\Http\Router\ConfigKey as RouterConfigKey;
use Componenta\Http\Router\Contract\RouteLocatorInterface;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getFactories(): array
    {
        return [
            AttributeRouteLocator::class => AttributeRouteLocatorFactory::class,
            RouteLocatorInterface::class => RouteLocatorFactory::class,
            InterceptedRouteHandlerResolver::class => InterceptedRouteHandlerResolverFactory::class,
        ];
    }

    protected function getAutowires(): array
    {
        return [
            RoutingBootloader::class,
            RouteCacheCompiler::class,
            RouterListCommand::class,
        ];
    }

    protected function getConfig(): array
    {
        return [
            AppConfigKey::BOOTLOADERS => [
                RoutingBootloader::class,
            ],
            ClassFinderConfigKey::LISTENERS => [
                AttributeRouteLocator::class,
            ],
            CompileConfigKey::LISTENER_COMPILERS => [
                RouteCacheCompiler::class,
            ],
            MiddlewareConfigKey::RESOLVERS => [
                InterceptedRouteHandlerResolver::class,
            ],
            ConsoleConfigKey::COMMANDS => [
                RouterListCommand::class,
            ],
            RouterConfigKey::ROUTES_FILE => 'config/routes.php',
        ];
    }
}
