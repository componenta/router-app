<?php

declare(strict_types=1);

use Componenta\Http\Router\App\Tests\BuildFixture;

use Componenta\App\Console\Command\BuildCommand;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey as DependencyKey;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;
use Symfony\Component\Console\Tester\CommandTester;

it('preserves external collector constraints through app build', function (string $operation): void {
    $root = sys_get_temp_dir() . '/external_build_' . bin2hex(random_bytes(8));
    mkdir($root);
    file_put_contents($root . '/routes.php',
        '<?php $routes->addRoute(\Componenta\Http\Router\RouteRecord::get("item", "/items/{id}", "handler"));');

    try {
        $container = static function (string $mode) use ($root): ContainerValue {
            $composition = (new ConfigFactory())->create(new Environment(['APP_ENV' => $mode]),
                new \Componenta\App\ConfigProvider(),
                new \Componenta\App\Console\ConfigProvider(),
                new \Componenta\Http\Router\ConfigProvider(),
                new \Componenta\Http\Router\App\ConfigProvider(),
                    BuildFixture::provider($root),
                static fn (): array => [
                    ConfigKey::ROUTES_FILE => 'routes.php',
                    ConfigKey::ROUTES_CACHE_FILE => 'routes.cache.php',
                    DependencyKey::DEPENDENCIES => [
                        DependencyKey::ALIASES => [CompilerInterface::class => 'test.route.compiler'],
                        DependencyKey::SERVICES => [
                            PathResolverInterface::class => new PathResolver($root),
                            \Componenta\App\ConfigKey::DISCOVERY_SOURCE => new ClassIterator([]),
                            'test.route.compiler' => new Compiler(defaultPatterns: ['id' => '[A-Z]+']),
                        ],
                    ],
                ],
            );
            return (new ContainerFactory())->create($composition->config, $composition->dependencies);
        };
        $source = $container('development')->get(RouteLocatorInterface::class)->getRoutes();
        $external = new class($source) implements RouteCollectorInterface {
            public function __construct(private Routes $routes) {}
            public function has(string $name): bool { return $this->routes->has($name); }
            public function getRoute(string $name): RouteRecord { return $this->routes->getRoute($name); }
            public function toArray(): array { return $this->routes->toArray(); }
            public function count(): int { return $this->routes->count(); }
            public function getIterator(): Traversable { return $this->routes->getIterator(); }
        };
        $observe = static fn ($routes, $collector): mixed => $operation === 'generate'
            ? $routes->generate($collector, 'item', ['id' => 'ABC'])
            : $routes->match($collector, '/items/ABC', 'GET')->parameters;
        $expected = $operation === 'generate' ? '/items/ABC' : ['id' => 'ABC'];
        expect($observe($source, $external))->toBe($expected)
            ->and($observe($container('production')->get(RouteLocatorInterface::class)->getRoutes(), $external))->toBe($expected)
            ->and((new CommandTester($container('production')->get(BuildCommand::class)))->execute([]))->toBe(0);

        $built = $container('production')->get(RouteLocatorInterface::class)->getRoutes();
        expect($built)->toBeInstanceOf(CompiledRoutes::class)
            ->and($observe($built, $built))->toBe($expected)
            ->and($observe($built, $external))->toBe($expected);
    } finally {
        BuildFixture::remove($root);
    }
})->with(['generate', 'match']);
