<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Boot;

use Componenta\App\Boot\BootContext;
use Componenta\App\Boot\BootloaderInterface;
use Componenta\App\Boot\ScopedBootloaderSupport;
use Componenta\App\Boot\Target\HttpBootTargetInterface;
use Componenta\App\Scope;
use Componenta\Http\Router\Middleware\DispatchRouteMiddleware;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Componenta\Scope\Scopes;

final class RoutingBootloader implements BootloaderInterface
{
    use ScopedBootloaderSupport;

    private const int ROUTING_PRIORITY = 50;

    public Scopes $scopes {
        get => Scopes::of(Scope::HTTP);
    }

    public function boot(BootContext $context): void
    {
        $app = $context->target(HttpBootTargetInterface::class);
        $app->pipe(MatchRouteMiddleware::class, self::ROUTING_PRIORITY);
        $app->pipe(DispatchRouteMiddleware::class, self::ROUTING_PRIORITY);
    }

}
