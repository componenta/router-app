<?php

declare(strict_types=1);

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey as DIConfigKey;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Http\Router\App\Tests\BuildFixture;
use Componenta\Http\Router\ConfigKey;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;

it('publishes only through the App store even when a legacy cache path targets a source file', function (): void {
    $root = sys_get_temp_dir() . '/route_ownership_' . bin2hex(random_bytes(8));
    mkdir($root);
    $source = '<?php $routes->addRoute(\\Componenta\\Http\\Router\\RouteRecord::get("home", "/", "Handler"));';
    file_put_contents($root . '/routes.php', $source);
    try {
        $composition = (new ConfigFactory())->create(new Environment([]),
            new \Componenta\App\ConfigProvider(),
            new \Componenta\Http\Router\ConfigProvider(),
            new \Componenta\Http\Router\App\ConfigProvider(),
            BuildFixture::provider($root),
            static fn (): array => [
                ConfigKey::ROUTES_FILE => 'routes.php',
                ConfigKey::ROUTES_CACHE_FILE => 'routes.php',
                DIConfigKey::DEPENDENCIES => [DIConfigKey::SERVICES => [
                    PathResolverInterface::class => new PathResolver($root),
                    \Componenta\App\ConfigKey::DISCOVERY_SOURCE => new ClassIterator([]),
                ]],
            ],
        );
        $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
        $orchestrator = $container->get(ApplicationBuildOrchestrator::class);
        $orchestrator->build();
        expect(BuildFixture::read($root))->toHaveKey('routeData');
        $orchestrator->clean();
        expect(file_get_contents($root . '/routes.php'))->toBe($source);
    } finally {
        BuildFixture::remove($root);
    }
});
