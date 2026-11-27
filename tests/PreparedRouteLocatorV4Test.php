<?php

declare(strict_types=1);

use Componenta\Http\Router\App\Build\RouteBuilder;
use Componenta\Http\Router\App\Locator\PreparedRouteLocator;
use Componenta\Http\Router\App\Tests\BuildFixture;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('uses a valid selected artifact and returns to source for context or an incompatible schema', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('home', '/', 'Controller'));
    $locator = new class($source) implements RouteLocatorInterface {
        public int $calls = 0;
        public function __construct(private Routes $source) {}
        public function getRoutes(array $context = []): RouteCollectorInterface
        {
            ++$this->calls;
            return $this->source;
        }
    };
    $root = sys_get_temp_dir() . '/router_prepared_' . bin2hex(random_bytes(8));
    $data = (new RouteCacheGenerator())->compile($source);
    try {
        $build = BuildFixture::publish($root, $data);
        $prepared = new PreparedRouteLocator($build, static fn () => $locator);
        expect($prepared->getRoutes())->toBeInstanceOf(CompiledRoutes::class)
            ->and($locator->calls)->toBe(0)
            ->and($prepared->getRoutes(['context' => true]))->toBe($source);

        $future = BuildFixture::publish($root, $data, RouteCacheGenerator::CACHE_VERSION + 1);
        expect((new PreparedRouteLocator($future, static fn () => $locator))->getRoutes())->toBe($source);
        $invalid = BuildFixture::publish($root, []);
        expect((new PreparedRouteLocator($invalid, static fn () => $locator))->getRoutes())->toBe($source);
        expect($prepared->getRoutes())->toBeInstanceOf(CompiledRoutes::class);
    } finally {
        BuildFixture::remove($root);
    }
});

it('retains the selected generation when another build is published or cleaned', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('home', '/', 'Controller'));
    $locator = new class($source) implements RouteLocatorInterface {
        public function __construct(private Routes $source) {}
        public function getRoutes(array $context = []): RouteCollectorInterface { return $this->source; }
    };
    $root = sys_get_temp_dir() . '/router_mode_' . bin2hex(random_bytes(8));
    try {
        $store = BuildFixture::store($root);
        $context = $store->begin();
        $context->skip(RouteBuilder::ARTIFACT_ID, 'The first build used dynamic routes.');
        $old = $store->publish($context);
        $fallback = new PreparedRouteLocator($old, static fn () => $locator);
        expect($fallback->getRoutes())->toBe($source);
        $new = BuildFixture::publish($root, (new RouteCacheGenerator())->compile($source));
        $prepared = new PreparedRouteLocator($new, static fn () => $locator);
        expect($fallback->getRoutes())->toBe($source)
            ->and($prepared->getRoutes())->toBeInstanceOf(CompiledRoutes::class);
        $store->clean();
        expect($store->load())->toBeNull()
            ->and($prepared->getRoutes())->toBeInstanceOf(CompiledRoutes::class);
    } finally {
        BuildFixture::remove($root);
    }
});
