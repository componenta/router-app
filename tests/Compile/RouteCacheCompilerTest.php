<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Http\Router\App\Compile\RouteCacheCompiler;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\Locator\RouteLocator;
use Componenta\Http\Router\RouteRecord;
use Componenta\Stdlib\PathResolverInterface;

if (!function_exists('routerAppAttributeLocator')) {
    function routerAppAttributeLocator(string $routesFile): AttributeRouteLocator
    {
        return new AttributeRouteLocator(new RouteLocator($routesFile));
    }
}

describe('RouteCacheCompiler', function () {
    it('supports only attribute route locators', function () {
        $compiler = new RouteCacheCompiler(new Config([
            ConfigKey::ROUTES_FILE => 'config/routes.php',
        ]));

        expect($compiler->supports(routerAppAttributeLocator(__FILE__)))->toBeTrue()
            ->and($compiler->supports(new stdClass()))->toBeFalse();
    });

    it('emits a sidecar route cache file for collected routes', function () {
        $routesFile = tempnam(sys_get_temp_dir(), 'routes-');
        expect($routesFile)->toBeString();

        $locator = routerAppAttributeLocator($routesFile);
        $locator->getRoutes()->addRoute(RouteRecord::get('home', '/', 'HomeController'));

        $compiler = new RouteCacheCompiler(new Config([
            ConfigKey::ROUTES_FILE => $routesFile,
            ConfigKey::ROUTES_CACHE_FILE => 'var/cache/build/routes.cache.php',
        ]));

        $result = $compiler->compile($locator, sys_get_temp_dir());

        @unlink($routesFile);

        $cacheFile = tempnam(sys_get_temp_dir(), 'routes-cache-');
        expect($cacheFile)->toBeString();

        file_put_contents($cacheFile, $result->files['var/cache/build/routes.cache.php']);
        $cache = require $cacheFile;
        @unlink($cacheFile);

        expect($result->configKey)->toBeNull()
            ->and($result->files)->toHaveKey('var/cache/build/routes.cache.php')
            ->and($cache['routeData']['home']['path'])->toBe('/')
            ->and($cache['routeData']['home']['handler'])->toBe('HomeController');
    });

    it('resolves the explicit cache file through the path resolver', function () {
        $baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'componenta-route-cache-test';
        $routesFile = 'config/routes.php';
        $cacheFile = $baseDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'routes.cache.php';

        $locator = routerAppAttributeLocator(__FILE__);
        $locator->getRoutes()->addRoute(RouteRecord::get('home', '/', 'HomeController'));

        $compiler = new RouteCacheCompiler(
            new Config([
                ConfigKey::ROUTES_FILE => $routesFile,
                ConfigKey::ROUTES_CACHE_FILE => 'var/cache/build/routes.cache.php',
            ]),
            new class ($baseDir) implements PathResolverInterface {
                public function __construct(public string $baseDir) {}

                public function resolve(string $path): string
                {
                    return $this->baseDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
                }
            },
        );

        $result = $compiler->compile($locator, sys_get_temp_dir());

        expect($result->files)->toHaveKey($cacheFile);
    });

    it('requires a routes file config value', function () {
        $compiler = new RouteCacheCompiler(new Config([]));

        expect(fn () => $compiler->compile(routerAppAttributeLocator(__FILE__), sys_get_temp_dir()))
            ->toThrow(RuntimeException::class, 'Cannot compile routes');
    });
});
