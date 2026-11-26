<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App;

use Componenta\App\Console\ConfigKey as ConsoleConfigKey;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Http\Router\App\Console\RouterListCommand;
use Componenta\Http\Router\App\Boot\RoutingBootloader;
use Componenta\Http\Router\App\Build\RouteBuilder;
use Componenta\Http\Router\App\Build\RouteBuilderFactory;
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
            RouteBuilder::class => RouteBuilderFactory::class,
            AttributeRouteLocator::class => AttributeRouteLocatorFactory::class,
            RouteLocatorInterface::class => RouteLocatorFactory::class,
            InterceptedRouteHandlerResolver::class => InterceptedRouteHandlerResolverFactory::class,
        ];
    }

    protected function getAliases(): array
    {
        return [RouterConfigKey::HANDLER_RESOLVER => InterceptedRouteHandlerResolver::class];
    }

    protected function getConfig(): array
    {
        return [
            AppConfigKey::BOOTLOADERS => [
                RoutingBootloader::class,
            ],
            AppConfigKey::BUILDERS => [RouteBuilder::class],
            ConsoleConfigKey::COMMANDS => [
                RouterListCommand::class,
            ],
            RouterConfigKey::ROUTES_FILE => 'config/routes.php',
        ];
    }
}
