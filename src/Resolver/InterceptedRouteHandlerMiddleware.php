<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Resolver;

use Componenta\Http\Responder;
use Componenta\Http\Router\Resolver\ResolvesHandlerResult;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\PipelineInterface;
use Componenta\Interceptor\Scope;
use Componenta\Reflection\Reflection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionFunctionAbstract;

/**
 * Dispatches a route handler through the HTTP-scoped interceptor pipeline.
 */
final readonly class InterceptedRouteHandlerMiddleware implements MiddlewareInterface
{
    use ResolvesHandlerResult;

    /** @var callable */
    private mixed $handler;

    private ReflectionFunctionAbstract $reflector;

    public function __construct(
        callable $handler,
        private PipelineInterface $pipeline,
        private ?Responder $responder = null,
    ) {
        $this->handler = $handler;
        $this->reflector = Reflection::callable($handler);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = new CallableContext($this->handler, [
            ServerRequestInterface::class => $request,
            RequestHandlerInterface::class => $handler,
        ], [
            ServerRequestInterface::class => $request,
            CallableContext::SCOPE_ATTRIBUTE => Scope::HTTP,
        ], $this->reflector);

        $result = $this->pipeline->handle($context);

        return $this->resolveResult($result, $request, $handler);
    }
}
