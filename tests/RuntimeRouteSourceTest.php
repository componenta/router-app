<?php

declare(strict_types=1);

use Componenta\App\Console\Command\BuildCommand;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Http\Router\Attribute\Route;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[Route('attribute', '/attribute/{id}')]
final class RouteBuildSnapshotAction { public function __invoke(): string { return 'ok'; } }

it('builds and rebuilds file and attribute routes using the current source inputs', function (): void {
    $root = sys_get_temp_dir() . '/runtime_routes_' . bin2hex(random_bytes(8));
    mkdir($root);
    file_put_contents($root . '/prefix.txt', '/initial');
    file_put_contents($root . '/routes.php', <<<'PHP'
<?php
$routes->addRoute(\Componenta\Http\Router\RouteRecord::get(
    'item', file_get_contents(__DIR__ . '/prefix.txt') . '/items/{id}', 'ItemHandler',
));
PHP);
    try {
        $container = static function (string $mode, bool $optimized = true, bool $forceSource = false) use ($root): ContainerValue {
            $providers = [new \Componenta\App\ConfigProvider(), new \Componenta\App\Console\ConfigProvider(),
                new \Componenta\Http\Router\ConfigProvider(), new \Componenta\Http\Router\App\ConfigProvider()];
            $providers[] = static fn (): array => [
                ConfigKey::ROUTES_FILE => 'routes.php',
                ConfigKey::ROUTES_CACHE_FILE => 'optimized.php',
                ConfigKey::COMPILED_PIPELINE => $optimized,
                \Componenta\Config\ConfigKey::DEPENDENCIES => [\Componenta\Config\ConfigKey::SERVICES => [
                    PathResolverInterface::class => new PathResolver($root),
                    \Componenta\App\ConfigKey::DISCOVERY_SOURCE => new ClassIterator([new ClassInfo(RouteBuildSnapshotAction::class)]),
                    \Componenta\ClassFinder\ClassIteratorInterface::class => new ClassIterator([new ClassInfo(RouteBuildSnapshotAction::class)]),
                ]],
            ];
            $composition = (new ConfigFactory())->create(new Environment(['APP_ENV' => $mode, 'APP_DISCOVERY_SOURCE' => $forceSource]), ...$providers);
            return (new ContainerFactory())->create($composition->config, $composition->dependencies);
        };
        $assertRoutes = static function ($routes, string $prefix): void {
            expect(array_keys($routes->toArray()))->toBe(['item', 'attribute'])
                ->and($routes->getRoute('item')->path)->toBe($prefix . '/items/{id}')
                ->and($routes->generate($routes, 'item', ['id' => 42]))->toBe($prefix . '/items/42')
                ->and($routes->match($routes, $prefix . '/items/42', 'GET')->parameters)->toBe(['id' => 42])
                ->and($routes->generate($routes, 'attribute', ['id' => 7]))->toBe('/attribute/7')
                ->and($routes->match($routes, '/attribute/7', 'GET')->parameters)->toBe(['id' => 7]);
        };
        $assertRoutes($container('production')->get(RouteLocatorInterface::class)->getRoutes(), '/initial');
        foreach (['/initial', '/updated'] as $prefix) {
            file_put_contents($root . '/prefix.txt', $prefix);
            expect((new CommandTester($container('production')->get(BuildCommand::class)))->execute([]))->toBe(0)
                ->and(is_file($root . '/optimized.php'))->toBeTrue();
            $built = $container('production')->get(RouteLocatorInterface::class)->getRoutes();
            expect($built)->toBeInstanceOf(CompiledRoutes::class);
            $assertRoutes($built, $prefix);
            $assertRoutes($container('development')->get(RouteLocatorInterface::class)->getRoutes(), $prefix);
            $assertRoutes($container('production', false)->get(RouteLocatorInterface::class)->getRoutes(), $prefix);
        }
        file_put_contents($root . '/prefix.txt', '/recovery');
        $assertRoutes($container('production')->get(RouteLocatorInterface::class)->getRoutes(), '/updated');
        $recovery = $container('production', forceSource: true)->get(RouteLocatorInterface::class)->getRoutes();
        expect($recovery)->not->toBeInstanceOf(CompiledRoutes::class);
        $assertRoutes($recovery, '/recovery');
    } finally {
        foreach (['routes.php', 'prefix.txt', 'optimized.php'] as $file) {
            if (is_file($root . '/' . $file)) { unlink($root . '/' . $file); }
        }
        rmdir($root);
    }
});

it('uses prepared classes for runtime fallback and current classes for the next build', function (): void {
    $root = sys_get_temp_dir() . '/route_discovery_sources_' . bin2hex(random_bytes(8));
    mkdir($root);
    file_put_contents($root . '/routes.php', '<?php $routes->addRoute(\\Componenta\\Http\\Router\\RouteRecord::get("file", "/file", "FileHandler"));');
    $sourceRequests = 0;
    $create = static function () use ($root, &$sourceRequests): ContainerValue {
        $composition = (new ConfigFactory())->create(new Environment(['APP_ENV' => 'production']),
            new \Componenta\Http\Router\ConfigProvider(), new \Componenta\Http\Router\App\ConfigProvider(),
            static function () use ($root, &$sourceRequests): array {
                return [ConfigKey::ROUTES_FILE => 'routes.php', ConfigKey::ROUTES_CACHE_FILE => 'routes.cache.php',
                    \Componenta\Config\ConfigKey::DEPENDENCIES => [
                        \Componenta\Config\ConfigKey::SERVICES => [
                            PathResolverInterface::class => new PathResolver($root),
                            \Componenta\ClassFinder\ClassIteratorInterface::class => new ClassIterator([]),
                        ],
                        \Componenta\Config\ConfigKey::FACTORIES => [
                            \Componenta\App\ConfigKey::DISCOVERY_SOURCE => static function () use (&$sourceRequests) {
                                ++$sourceRequests;
                                return new ClassIterator([new ClassInfo(RouteBuildSnapshotAction::class)]);
                            },
                        ],
                    ],
                ];
            });
        return (new ContainerFactory())->create($composition->config, $composition->dependencies);
    };
    try {
        $container = $create();
        expect(array_keys($container->get(RouteLocatorInterface::class)->getRoutes()->toArray()))
            ->toBe(['file'])->and($sourceRequests)->toBe(0);
        $container->get(\Componenta\Http\Router\App\Build\RouteBuilder::class)->build();
        expect($sourceRequests)->toBe(1);
        $routes = $create()->get(RouteLocatorInterface::class)->getRoutes();
        expect($routes)->toBeInstanceOf(CompiledRoutes::class)
            ->and(array_keys($routes->toArray()))->toBe(['file', 'attribute'])
            ->and($sourceRequests)->toBe(1);
    } finally {
        foreach (['routes.php', 'routes.cache.php'] as $file) {
            if (is_file($root . '/' . $file)) { unlink($root . '/' . $file); }
        }
        rmdir($root);
    }
});
