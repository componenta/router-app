<?php

declare(strict_types=1);

use Componenta\App\Build\PhpMapFile;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Http\Router\App\Build\RouteBuilder;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\RouteConflictException;
use Componenta\Http\Router\Locator\RouteLocator;

it('rebuilds current file routes without replacing an existing runtime snapshot', function (): void {
    $root = sys_get_temp_dir() . '/router-rebuild-' . bin2hex(random_bytes(8));
    mkdir($root);
    $file = $root . '/routes.php';
    $map = $root . '/map.php';
    $generation = str_repeat('a', 32);
    $source = new AttributeRouteLocator(new RouteLocator($file, useCache: false), new ClassIterator([]));
    $builder = new RouteBuilder($source, $map, new RouteCacheGenerator(), $generation);
    try {
        file_put_contents($file, '<?php $routes->addRoute(\Componenta\Http\Router\RouteRecord::get("item", "/old", "Handler"));');
        $runtime = $source->getRoutes();
        $builder->build();
        expect(CompiledRoutes::fromArray(PhpMapFile::read($map, $generation))->getRoute('item')->path)->toBe('/old');

        file_put_contents($file, '<?php $routes->addRoute(\Componenta\Http\Router\RouteRecord::get("item", "/new", "Handler"));');
        $builder->build();
        expect(CompiledRoutes::fromArray(PhpMapFile::read($map, $generation))->getRoute('item')->path)->toBe('/new')
            ->and($source->getRoutes())->toBe($runtime)
            ->and($runtime->getRoute('item')->path)->toBe('/old');
        $builder->clean();
        $builder->build();
        expect(CompiledRoutes::fromArray(PhpMapFile::read($map, $generation))->getRoute('item')->path)->toBe('/new');

        file_put_contents($file, '<?php $routes->addRoute(\Componenta\Http\Router\RouteRecord::get("item", "/new", "Handler")); $routes->addRoute(\Componenta\Http\Router\RouteRecord::get("conflict", "/new", "Other"));');
        expect(fn () => $builder->build())->toThrow(RouteConflictException::class);
    } finally {
        foreach ([$file, $map] as $path) { if (is_file($path)) { unlink($path); } }
        rmdir($root);
    }
});

it('prepares built-in generation syntaxes through the same router preparation contract', function (): void {
    $root = sys_get_temp_dir() . '/router-built-in-' . bin2hex(random_bytes(8));
    mkdir($root);
    $file = $root . '/routes.php';
    $map = $root . '/map.php';
    $generation = str_repeat('b', 32);
    try {
        file_put_contents($file, <<<'PHP'
<?php
$routes = new \Componenta\Http\Router\Routes(syntax: new \Componenta\Http\Router\Syntax\SquareBracketSyntax());
$routes->addRoute(\Componenta\Http\Router\RouteRecord::get('item', '/items/[id]', 'Handler'));
PHP);
        $source = new AttributeRouteLocator(new RouteLocator($file, useCache: false), new ClassIterator([]));
        (new RouteBuilder($source, $map, new RouteCacheGenerator(), $generation))->build();
        $cached = CompiledRoutes::fromArray(PhpMapFile::read($map, $generation));
        expect($cached->generate($cached, 'item', ['id' => 42]))->toBe('/items/42')
            ->and($cached->match($cached, '/items/42', 'GET')->parameters)->toBe(['id' => 42]);
    } finally {
        foreach ([$file, $map] as $path) { if (is_file($path)) { unlink($path); } }
        rmdir($root);
    }
});
