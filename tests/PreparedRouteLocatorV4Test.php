<?php

declare(strict_types=1);

use Componenta\App\Build\PhpMapFile;
use Componenta\Http\Router\App\Locator\PreparedRouteLocator;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('uses only a valid route map from the selected discovery generation', function (): void {
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

    $file = tempnam(sys_get_temp_dir(), 'router-app-map-');
    if ($file === false) { throw new RuntimeException('Cannot create map file.'); }
    $generation = str_repeat('a', 32);
    $data = (new RouteCacheGenerator())->compile($source);
    try {
        PhpMapFile::write($file, $data, $generation);
        $prepared = new PreparedRouteLocator($file, $generation, static fn () => $locator);
        expect($prepared->getRoutes())->toBeInstanceOf(CompiledRoutes::class)
            ->and($locator->calls)->toBe(0)
            ->and($prepared->getRoutes(['context' => true]))->toBe($source);

        $stale = new PreparedRouteLocator($file, str_repeat('b', 32), static fn () => $locator);
        expect($stale->getRoutes())->toBe($source);

        file_put_contents($file, '<?php echo "corrupted"; return [];');
        $broken = new PreparedRouteLocator($file, $generation, static fn () => $locator);
        ob_start();
        try {
            expect($broken->getRoutes())->toBe($source);
            expect(ob_get_contents())->toBe('');
        } finally {
            ob_end_clean();
        }

        PhpMapFile::write($file, [], $generation);
        $fallback = new PreparedRouteLocator($file, $generation, static fn () => $locator);
        expect($fallback->getRoutes())->toBe($source);
    } finally {
        PhpMapFile::remove($file);
    }
});


it('does not switch a running application from source to prepared routing after its first resolution', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('home', '/', 'Controller'));
    $locator = new class($source) implements RouteLocatorInterface {
        public function __construct(private Routes $source) {}
        public function getRoutes(array $context = []): RouteCollectorInterface { return $this->source; }
    };
    $file = tempnam(sys_get_temp_dir(), 'router_app_mode_');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    $generation = str_repeat('a', 32);
    try {
        $prepared = new PreparedRouteLocator($file, $generation, static fn () => $locator);
        expect($prepared->getRoutes())->toBe($source);
        PhpMapFile::write($file, (new RouteCacheGenerator())->compile($source), $generation);
        expect($prepared->getRoutes())->toBe($source)
            ->and((new PreparedRouteLocator($file, $generation, static fn () => $locator))->getRoutes())->toBeInstanceOf(CompiledRoutes::class);
    } finally {
        PhpMapFile::remove($file);
    }
});
