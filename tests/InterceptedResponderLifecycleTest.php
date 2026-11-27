<?php

declare(strict_types=1);

use Componenta\App\Build\PreparedBuild;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey as DependencyKey;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\ResolutionException;
use Componenta\Http\Responder;
use Componenta\Http\Router\App\Build\RouteBuilder;
use Componenta\Http\Router\App\ConfigProvider;
use Componenta\Http\Router\App\Tests\BuildFixture;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\ConfigKey as RouterConfigKey;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\Middleware\DispatchRouteMiddleware;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Componenta\Interceptor\Attribute\After;
use Componenta\Interceptor\Attribute\InterceptCallback;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ConfigKey as InterceptorConfigKey;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RouterAppPsrResultAction
{
    public function __invoke(#[CurrentRequest] ServerRequestInterface $request): mixed
    {
        $response = new Response(202, ['X-Sequence' => (string) $request->getAttribute('sequence')]);

        return match ($request->getAttribute('kind')) {
            'middleware' => new class ($response) implements MiddlewareInterface {
                public function __construct(private ResponseInterface $response) {}
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    return $this->response;
                }
            },
            'handler' => new class ($response) implements RequestHandlerInterface {
                public function __construct(private ResponseInterface $response) {}
                public function handle(ServerRequestInterface $request): ResponseInterface { return $this->response; }
            },
            default => $response,
        };
    }
}

final class RouterAppShortCircuitAction
{
    #[InterceptCallback(static function (CallableContextInterface $context, ContextHandlerInterface $next): ResponseInterface {
        $request = $context->getAttribute(ServerRequestInterface::class);
        if (($failure = $request->getAttribute('failure')) !== null) {
            throw $failure;
        }

        return new Response(203, ['X-Sequence' => (string) $request->getAttribute('sequence')]);
    })]
    public function __invoke(): never
    {
        throw new LogicException('The callback must short-circuit this action.');
    }
}

final class RouterAppAfterResultAction
{
    #[After(static function (ResponseInterface $response, CallableContextInterface $context): array {
        return [
            'sequence' => $context->getAttribute(ServerRequestInterface::class)->getAttribute('sequence'),
            'status' => $response->getStatusCode(),
        ];
    })]
    public function __invoke(): ResponseInterface
    {
        return new Response(202);
    }
}

beforeEach(function (): void {
    $this->responderRoot = sys_get_temp_dir() . '/router_responder_' . bin2hex(random_bytes(8));
    mkdir($this->responderRoot);
    file_put_contents($this->responderRoot . '/routes.php', <<<'PHP'
<?php
$routes->addRoute(\Componenta\Http\Router\RouteRecord::get('psr', '/psr', \RouterAppPsrResultAction::class));
$routes->addRoute(\Componenta\Http\Router\RouteRecord::get('short', '/short', \RouterAppShortCircuitAction::class));
$routes->addRoute(\Componenta\Http\Router\RouteRecord::get('after', '/after', \RouterAppAfterResultAction::class));
PHP);
});

afterEach(function (): void {
    BuildFixture::remove($this->responderRoot);
});

/** @return array{ContainerValue, Closure(ServerRequestInterface): ResponseInterface} */
function interceptedResponderRuntime(string $root, bool $prepared, array $services = [], array $factories = []): array
{
    $create = static function (?PreparedBuild $snapshot = null) use ($root, $services, $factories): ContainerValue {
        $runtimeServices = [
            PathResolverInterface::class => new PathResolver($root),
            AppConfigKey::DISCOVERY_SOURCE => new ClassIterator([]),
            ...$services,
        ];
        if ($snapshot !== null) {
            $runtimeServices[AppConfigKey::PREPARED_BUILD] = $snapshot;
        }
        $composition = (new ConfigFactory())->create(
            new Environment(['APP_ENV' => 'production']),
            new \Componenta\Http\Middleware\ConfigProvider(),
            new \Componenta\Interceptor\ConfigProvider(),
            new \Componenta\Http\Router\ConfigProvider(),
            new ConfigProvider(),
            static fn (): array => [
                RouterConfigKey::ROUTES_FILE => 'routes.php',
                InterceptorConfigKey::HTTP_INTERCEPTORS => [AttributeInterceptor::class],
                DependencyKey::DEPENDENCIES => [
                    DependencyKey::SERVICES => $runtimeServices,
                    DependencyKey::FACTORIES => $factories,
                ],
            ],
        );

        return (new ContainerFactory())->create($composition->config, $composition->dependencies);
    };

    $container = $create();
    if ($prepared) {
        BuildFixture::build($root, $container->get(RouteBuilder::class));
        $container = $create(BuildFixture::store($root)->load());
    }
    expect($container->get(RouteLocatorInterface::class)->getRoutes() instanceof CompiledRoutes)->toBe($prepared);
    $match = $container->get(MatchRouteMiddleware::class);
    $dispatch = $container->get(DispatchRouteMiddleware::class);
    $next = new class ($dispatch) implements RequestHandlerInterface {
        public function __construct(private DispatchRouteMiddleware $dispatch) {}
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return $this->dispatch->process($request, new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new LogicException('All fixture routes must return a response.');
                }
            });
        }
    };

    return [$container, static fn (ServerRequestInterface $request): ResponseInterface => $match->process($request, $next)];
}

it('keeps PSR results and short-circuit callbacks independent of an unused responder', function (bool $prepared, bool $registered): void {
    $resolved = 0;
    [$container, $handle] = interceptedResponderRuntime($this->responderRoot, $prepared, factories: $registered ? [
        Responder::class => static function () use (&$resolved): never {
            ++$resolved;
            throw new LogicException('An unused responder must not be resolved.');
        },
    ] : []);
    expect($container->has(ResponseFactoryInterface::class))->toBeFalse()->and($resolved)->toBe(0);

    foreach ([1, 2] as $sequence) {
        foreach (['response', 'middleware', 'handler', 'callback'] as $kind) {
            $request = (new ServerRequest('GET', $kind === 'callback' ? '/short' : '/psr'))
                ->withAttribute('sequence', $sequence)->withAttribute('kind', $kind);
            $response = $handle($request);
            expect($response->getHeaderLine('X-Sequence'))->toBe((string) $sequence)
                ->and($response->getStatusCode())->toBe($kind === 'callback' ? 203 : 202)
                ->and($resolved)->toBe(0);
        }
    }

    $failure = new LogicException('A callback failure must retain its identity.');
    try {
        $handle((new ServerRequest('GET', '/short'))->withAttribute('failure', $failure));
        throw new RuntimeException('The callback failure was suppressed.');
    } catch (LogicException $error) {
        expect($error)->toBe($failure)->and($resolved)->toBe(0);
    }
})->with(['source' => false, 'prepared' => true])->with(['unregistered' => false, 'registered' => true]);

it('normalizes callback results with the application responder only when needed', function (bool $prepared, bool $lazy): void {
    $responses = new class implements ResponseFactoryInterface {
        public int $calls = 0;
        public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
        {
            ++$this->calls;
            return new Response($code, ['X-Responder' => 'application']);
        }
    };
    $responder = new Responder($responses, new Psr17Factory());
    $initialized = 0;
    if ($lazy) {
        $backing = $responder;
        $responder = (new ReflectionClass(Responder::class))->newLazyProxy(static function () use ($backing, &$initialized): Responder {
            ++$initialized;
            return $backing;
        });
    }
    [$container, $handle] = interceptedResponderRuntime($this->responderRoot, $prepared, [Responder::class => $responder]);
    expect($container->has(ResponseFactoryInterface::class))->toBeFalse()
        ->and($responses->calls)->toBe(0)->and($initialized)->toBe(0);

    $handle((new ServerRequest('GET', '/psr'))->withAttribute('sequence', 'unused'));
    expect($responses->calls)->toBe(0)->and($initialized)->toBe(0);

    foreach ([1, 2] as $sequence) {
        $response = $handle((new ServerRequest('GET', '/after'))->withAttribute('sequence', $sequence));
        expect($response->getHeaderLine('X-Responder'))->toBe('application')
            ->and(json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR))->toBe(['sequence' => $sequence, 'status' => 202])
            ->and($responses->calls)->toBe($sequence)
            ->and($initialized)->toBe($lazy ? 1 : 0);
    }
})->with(['source' => false, 'prepared' => true])->with(['initialized' => false, 'lazy' => true]);

it('propagates a responder factory failure only when an intercepted result needs normalization', function (bool $prepared): void {
    $failure = new LogicException('The configured responder factory failed.');
    $resolved = 0;
    [, $handle] = interceptedResponderRuntime($this->responderRoot, $prepared, factories: [
        Responder::class => static function () use ($failure, &$resolved): never {
            ++$resolved;
            throw $failure;
        },
    ]);
    $handle(new ServerRequest('GET', '/psr'));
    expect($resolved)->toBe(0);

    try {
        $handle((new ServerRequest('GET', '/after'))->withAttribute('sequence', 1));
        throw new RuntimeException('The configured failure was suppressed.');
    } catch (ResolutionException $error) {
        $cause = $error;
        while ($cause->getPrevious() !== null) { $cause = $cause->getPrevious(); }
        expect($cause)->toBe($failure)->and($resolved)->toBe(1);
    }
})->with(['source' => false, 'prepared' => true]);
