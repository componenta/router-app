<?php

declare(strict_types=1);

use Componenta\App\Console\ConfigKey as ConsoleConfigKey;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\App\Boot\BootContext;
use Componenta\App\Boot\Target\HttpBootTargetInterface;
use Componenta\App\Scope;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\Http\Middleware\ConfigKey as MiddlewareConfigKey;
use Componenta\Http\Router\App\Boot\RoutingBootloader;
use Componenta\Http\Router\App\Build\RouteBuilder;
use Componenta\Http\Router\App\Build\RouteBuilderFactory;
use Componenta\Http\Router\App\Console\RouterListCommand;
use Componenta\Http\Router\App\ConfigProvider;
use Componenta\Http\Router\App\Factory\AttributeRouteLocatorFactory;
use Componenta\Http\Router\App\Factory\InterceptedRouteHandlerResolverFactory;
use Componenta\Http\Router\App\Factory\RouteLocatorFactory;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\App\Resolver\InterceptedRouteHandlerResolver;
use Componenta\Http\Router\ConfigKey as RouterConfigKey;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\Middleware\DispatchRouteMiddleware;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Componenta\DI\CallableExecutorInterface;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\PipelineInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RouterAppFactoryTestContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(private array $entries) {}

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new RuntimeException("Missing container entry: {$id}");
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

final class RouterAppFactoryCallableExecutor implements CallableExecutorInterface
{
    public function resolve(mixed $callable): callable
    {
        if (!is_callable($callable)) {
            throw new InvalidArgumentException('Callable expected.');
        }

        return $callable(...);
    }

    public function call(mixed $callable, array $params = []): mixed
    {
        return $this->resolve($callable)(...$params);
    }
}

final class RouterAppBootTarget implements HttpBootTargetInterface
{
    /** @var list<array{middleware: mixed, priority: int}> */
    public array $pipes = [];

    public function pipe(mixed $middleware, int $priority = 0): void
    {
        $this->pipes[] = [
            'middleware' => $middleware,
            'priority' => $priority,
        ];
    }
}

describe('router app ConfigProvider', function () {
    it('registers the route builder and runtime factories', function () {
        $config = (new ConfigProvider())();

        expect($config[AppConfigKey::BUILDERS])->toBe([RouteBuilder::class])
            ->and($config[AppConfigKey::BOOTLOADERS])->toBe([RoutingBootloader::class])
            ->and($config)->not->toHaveKey(MiddlewareConfigKey::RESOLVERS)
            ->and($config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::ALIASES][RouterConfigKey::HANDLER_RESOLVER])
            ->toBe(InterceptedRouteHandlerResolver::class)
            ->and($config[ConsoleConfigKey::COMMANDS])->toBe([
            RouterListCommand::class,
        ])->and($config[RouterConfigKey::ROUTES_FILE])->toBe('config/routes.php')
            ->and($config[DependencyConfigKey::DEPENDENCIES])->not->toHaveKey('autowires')
            ->and($config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES])
            ->toBe([
                RouteBuilder::class => RouteBuilderFactory::class,
                AttributeRouteLocator::class => AttributeRouteLocatorFactory::class,
                RouteLocatorInterface::class => RouteLocatorFactory::class,
                InterceptedRouteHandlerResolver::class => InterceptedRouteHandlerResolverFactory::class,
            ]);
    });

    it('builds the intercepted route handler resolver from the interceptor pipeline service', function () {
        $resolver = (new InterceptedRouteHandlerResolverFactory())(new RouterAppFactoryTestContainer([
            CallableExecutorInterface::class => new RouterAppFactoryCallableExecutor(),
            PipelineInterface::class => $this->createStub(PipelineInterface::class),
        ]));

        expect($resolver)->toBeInstanceOf(InterceptedRouteHandlerResolver::class);
    });

    it('registers routing middleware from the bootloader after default priority middleware', function () {
        $target = new RouterAppBootTarget();
        $bootloader = new RoutingBootloader();

        $bootloader->boot(new BootContext(
            new ContainerValue(new RouterAppFactoryTestContainer([]), new Config([], new \Componenta\Config\Environment([]))),
            Scope::HTTP,
            $target,
        ));

        expect($target->pipes)->toBe([
            [
                'middleware' => MatchRouteMiddleware::class,
                'priority' => 50,
            ],
            [
                'middleware' => DispatchRouteMiddleware::class,
                'priority' => 50,
            ],
        ]);
    });
});


it('selects exactly one intercepted route resolver and executes it in the composed middleware chain', function (): void {
    $interceptor = new class implements InterceptorInterface {
        public int $calls = 0;

        public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
        {
            ++$this->calls;
            return $handler->handle($context);
        }
    };
    $responseFactory = $this->createStub(ResponseFactoryInterface::class);
    $streamFactory = $this->createStub(StreamFactoryInterface::class);
    $composition = (new \Componenta\Config\ConfigFactory())->create(
        new \Componenta\Config\Environment([]),
        new \Componenta\Http\Middleware\ConfigProvider(),
        new \Componenta\Http\ResponderConfigProvider(),
        new \Componenta\Interceptor\ConfigProvider(),
        new \Componenta\Http\Router\ConfigProvider(),
        new ConfigProvider(),
        static fn (): array => [
            \Componenta\Interceptor\ConfigKey::HTTP_INTERCEPTORS => [$interceptor],
            DependencyConfigKey::DEPENDENCIES => [DependencyConfigKey::SERVICES => [
                ResponseFactoryInterface::class => $responseFactory,
                StreamFactoryInterface::class => $streamFactory,
            ]],
        ],
    );
    expect($composition->config->get(MiddlewareConfigKey::RESOLVERS))->toBe([RouterConfigKey::HANDLER_RESOLVER])
        ->and($composition->dependencies->sections[DependencyConfigKey::ALIASES][RouterConfigKey::HANDLER_RESOLVER])
        ->toBe(InterceptedRouteHandlerResolver::class);

    // CallableExecutorInterface belongs to DI's protected core services.
    // Use the actual container and interceptor provider instead of binding
    // a conflicting executor or a stub of the older pipeline contract.
    $container = (new \Componenta\DI\ContainerFactory())->create($composition->config, $composition->dependencies);
    expect($container->get(CallableExecutorInterface::class))->toBeInstanceOf(CallableExecutorInterface::class)
        ->and($container->get(PipelineInterface::class))->toBeInstanceOf(PipelineInterface::class);

    $request = $this->createStub(ServerRequestInterface::class);
    $response = $this->createStub(ResponseInterface::class);
    $next = $this->createMock(RequestHandlerInterface::class);
    $next->expects($this->never())->method('handle');

    $resolved = $container->get(\Componenta\Http\Middleware\MiddlewareFactory::class)->createMiddleware(
        new \Componenta\Http\Router\RouteHandler(
            static function (#[\Componenta\DI\Attribute\CurrentRequest] ServerRequestInterface $actual) use ($request, $response): ResponseInterface {
                if ($actual !== $request) {
                    throw new LogicException('Route handler did not receive the current request.');
                }
                return $response;
            },
        ),
    );
    expect($resolved)->toBeInstanceOf(\Componenta\Http\Router\App\Resolver\InterceptedRouteHandlerMiddleware::class)
        ->and($resolved->process($request, $next))->toBe($response)
        ->and($interceptor->calls)->toBe(1);
});
