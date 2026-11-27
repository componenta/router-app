<?php

declare(strict_types=1);

use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey as DIConfigKey;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Http\Router\App\Factory\RouteLocatorFactory;
use Componenta\Http\Router\ConfigKey;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;

it('refuses to assign an App-owned file to the route builder', function (string $reserved): void {
    $root = sys_get_temp_dir() . '/route_ownership_' . bin2hex(random_bytes(8));
    mkdir($root);
    try {
        $composition = (new ConfigFactory())->create(new Environment([]),
            static fn (): array => [
                ConfigKey::ROUTES_FILE => 'routes.php',
                ConfigKey::ROUTES_CACHE_FILE => $reserved,
                DIConfigKey::DEPENDENCIES => [DIConfigKey::SERVICES => [
                    PathResolverInterface::class => new PathResolver($root),
                ]],
            ]);
        $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
        expect(fn () => RouteLocatorFactory::cacheFile($container))->toThrow(InvalidArgumentException::class);
    } finally { rmdir($root); }
})->with([AppConfigKey::DISCOVERY_MAP_FILE, AppConfigKey::BOOT_MAP_FILE]);


it('protects discovery and boot artifacts through aliases before the build directory exists', function (string $reserved): void {
    $root = sys_get_temp_dir() . '/route_future_app_artifact_' . bin2hex(random_bytes(8));
    mkdir($root);
    if (!@symlink($root, $root . '/alias')) {
        rmdir($root);
        test()->markTestSkipped('Symbolic links unavailable.');
    }

    try {
        $composition = (new ConfigFactory())->create(new Environment([]),
            static fn (): array => [
                ConfigKey::ROUTES_FILE => 'routes.php',
                ConfigKey::ROUTES_CACHE_FILE => 'alias/' . $reserved,
                DIConfigKey::DEPENDENCIES => [DIConfigKey::SERVICES => [
                    PathResolverInterface::class => new PathResolver($root),
                ]],
            ]);
        $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
        expect(is_dir(dirname($root . '/' . $reserved)))->toBeFalse();
        expect(fn () => RouteLocatorFactory::cacheFile($container))
            ->toThrow(InvalidArgumentException::class, 'another application artifact');
    } finally {
        unlink($root . '/alias');
        rmdir($root);
    }
})->with([AppConfigKey::DISCOVERY_MAP_FILE, AppConfigKey::BOOT_MAP_FILE]);
