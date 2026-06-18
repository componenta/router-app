<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Factory;

use Componenta\DI\CallableExecutorInterface;
use Componenta\Http\Responder;
use Componenta\Http\Router\App\Resolver\InterceptedRouteHandlerResolver;
use Componenta\Interceptor\PipelineInterface;
use Psr\Container\ContainerInterface;

final readonly class InterceptedRouteHandlerResolverFactory
{
    public function __invoke(ContainerInterface $container): InterceptedRouteHandlerResolver
    {
        return new InterceptedRouteHandlerResolver(
            resolver: $container->get(CallableExecutorInterface::class),
            pipeline: $container->get(PipelineInterface::class),
            responder: $container->has(Responder::class) ? $container->get(Responder::class) : null,
        );
    }
}
