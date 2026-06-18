<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Resolver;

use Componenta\DI\CallableResolverInterface;
use Componenta\Http\Middleware\Resolver\MiddlewareResolverInterface;
use Componenta\Http\Responder;
use Componenta\Http\Router\Resolver\CallableResolver;
use Componenta\Http\Router\RouteHandler;
use Componenta\Interceptor\PipelineInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Resolves route handlers into PSR-15 middleware with interceptor support.
 */
final class InterceptedRouteHandlerResolver implements MiddlewareResolverInterface
{
    private CallableResolver $resolver;

    public function __construct(
        CallableResolverInterface $resolver,
        private readonly PipelineInterface $pipeline,
        private readonly ?Responder $responder = null,
    ) {
        $this->resolver = new CallableResolver($resolver);
    }

    public function resolve(mixed $middleware): ?MiddlewareInterface
    {
        if (!$middleware instanceof RouteHandler) {
            return null;
        }

        return new InterceptedRouteHandlerMiddleware(
            $this->resolver->resolve($middleware->value),
            $this->pipeline,
            $this->responder,
        );
    }
}
