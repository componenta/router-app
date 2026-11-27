<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Factory;

use Componenta\DI\CallableExecutorInterface;
use Componenta\Http\Responder;
use Componenta\Http\Router\App\Resolver\InterceptedRouteHandlerResolver;
use Componenta\Interceptor\PipelineInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;

final readonly class InterceptedRouteHandlerResolverFactory
{
    public function __invoke(ContainerInterface $container): InterceptedRouteHandlerResolver
    {
        $responder = null;
        if ($container->has(Responder::class)) {
            // DI may know an autowiring candidate without its HTTP factories.
            // Interceptors and handlers returning PSR results do not need it.
            $reflection = new ReflectionClass(Responder::class);
            $responder = $reflection->newLazyProxy(static function () use ($container, $reflection): Responder {
                // An application registration may itself be a native lazy proxy.
                return $reflection->initializeLazyObject($container->get(Responder::class));
            });
        }

        return new InterceptedRouteHandlerResolver(
            resolver: $container->get(CallableExecutorInterface::class),
            pipeline: $container->get(PipelineInterface::class),
            responder: $responder,
        );
    }
}
