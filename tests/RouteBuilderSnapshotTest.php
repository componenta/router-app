<?php

declare(strict_types=1);

use Componenta\Http\Router\App\Tests\BuildFixture;
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
    $source = new AttributeRouteLocator(new RouteLocator($file, useCache: false), new ClassIterator([]));
    $builder = new RouteBuilder($source, new RouteCacheGenerator());
    try {
        file_put_contents($file, '<?php $routes->addRoute(\Componenta\Http\Router\RouteRecord::get("item", "/old", "Handler"));');
        $runtime = $source->getRoutes();
        BuildFixture::build($root, $builder);
        expect(CompiledRoutes::fromArray(BuildFixture::read($root))->getRoute('item')->path)->toBe('/old');

        file_put_contents($file, '<?php $routes->addRoute(\Componenta\Http\Router\RouteRecord::get("item", "/new", "Handler"));');
        BuildFixture::build($root, $builder);
        expect(CompiledRoutes::fromArray(BuildFixture::read($root))->getRoute('item')->path)->toBe('/new')
            ->and($source->getRoutes())->toBe($runtime)
            ->and($runtime->getRoute('item')->path)->toBe('/old');
        BuildFixture::store($root)->clean();
        BuildFixture::build($root, $builder);
        expect(CompiledRoutes::fromArray(BuildFixture::read($root))->getRoute('item')->path)->toBe('/new');

        file_put_contents($file, '<?php $routes->addRoute(\Componenta\Http\Router\RouteRecord::get("item", "/new", "Handler")); $routes->addRoute(\Componenta\Http\Router\RouteRecord::get("conflict", "/new", "Other"));');
        $previous = BuildFixture::store($root)->load();
        expect(fn () => BuildFixture::build($root, $builder))->toThrow(RouteConflictException::class);
        expect(BuildFixture::store($root)->load()?->generation)->toBe($previous?->generation);
    } finally {
        BuildFixture::remove($root);
    }
});

it('prepares built-in generation syntaxes through the same router preparation contract', function (): void {
    $root = sys_get_temp_dir() . '/router-built-in-' . bin2hex(random_bytes(8));
    mkdir($root);
    $file = $root . '/routes.php';
    try {
        file_put_contents($file, <<<'PHP'
<?php
$routes = new \Componenta\Http\Router\Routes(syntax: new \Componenta\Http\Router\Syntax\SquareBracketSyntax());
$routes->addRoute(\Componenta\Http\Router\RouteRecord::get('item', '/items/[id]', 'Handler'));
PHP);
        $source = new AttributeRouteLocator(new RouteLocator($file, useCache: false), new ClassIterator([]));
        BuildFixture::build($root, new RouteBuilder($source, new RouteCacheGenerator()));
        $cached = CompiledRoutes::fromArray(BuildFixture::read($root));
        expect($cached->generate($cached, 'item', ['id' => 42]))->toBe('/items/42')
            ->and($cached->match($cached, '/items/42', 'GET')->parameters)->toBe(['id' => 42]);
    } finally {
        BuildFixture::remove($root);
    }
});
