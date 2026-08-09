<?php

declare(strict_types=1);

use Componenta\ClassFinder\Compile\ConfigKey as CompileConfigKey;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
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
use Componenta\Http\Router\App\Compile\RouteCacheCompiler;
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

final readonly class RouterAppFactoryPipeline implements PipelineInterface
{
    public function pipe(InterceptorInterface ...$interceptor): PipelineInterface
    {
        return $this;
    }

    public function handle(CallableContextInterface $context): mixed
    {
        return null;
    }

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return $handler->handle($context);
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
    it('registers the route cache compiler without a legacy autowire section', function () {
        $config = (new ConfigProvider())();

        expect($config[ClassFinderConfigKey::LISTENERS])->toBe([
            AttributeRouteLocator::class,
        ])->and($config[AppConfigKey::AUTOWIRE_ENTRY_CONTRIBUTORS])->toBe([
            AttributeRouteLocator::class,
        ])->and($config[AppConfigKey::BOOTLOADERS])->toBe([
            RoutingBootloader::class,
        ])->and($config[CompileConfigKey::LISTENER_COMPILERS])->toBe([
            RouteCacheCompiler::class,
        ])->and($config[MiddlewareConfigKey::RESOLVERS])->toBe([
            InterceptedRouteHandlerResolver::class,
        ])->and($config[ConsoleConfigKey::COMMANDS])->toBe([
            RouterListCommand::class,
        ])->and($config[RouterConfigKey::ROUTES_FILE])->toBe('config/routes.php')
            ->and($config[DependencyConfigKey::DEPENDENCIES])->not->toHaveKey('autowires')
            ->and($config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES])
            ->toBe([
                AttributeRouteLocator::class => AttributeRouteLocatorFactory::class,
                RouteLocatorInterface::class => RouteLocatorFactory::class,
                InterceptedRouteHandlerResolver::class => InterceptedRouteHandlerResolverFactory::class,
            ]);
    });

    it('builds the intercepted route handler resolver from the interceptor pipeline service', function () {
        $resolver = (new InterceptedRouteHandlerResolverFactory())(new RouterAppFactoryTestContainer([
            CallableExecutorInterface::class => new RouterAppFactoryCallableExecutor(),
            PipelineInterface::class => new RouterAppFactoryPipeline(),
        ]));

        expect($resolver)->toBeInstanceOf(InterceptedRouteHandlerResolver::class);
    });

    it('registers routing middleware from the bootloader after default priority middleware', function () {
        $target = new RouterAppBootTarget();
        $bootloader = new RoutingBootloader();

        $bootloader->boot(new BootContext(
            new ContainerValue(new RouterAppFactoryTestContainer([]), new Config([])),
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
