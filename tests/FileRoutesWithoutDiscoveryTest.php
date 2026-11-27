<?php

declare(strict_types=1);

use Componenta\App\Build\ApplicationBuildRunner;
use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\ConfigFactory;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Http\Router\App\Build\RouteBuilder;
use Componenta\Http\Router\App\Tests\BuildFixture;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Stdlib\PathResolver;

function fileRoutesWithoutDiscoveryDefinition(): ConfigDefinition
{
    return new ConfigDefinition(providers: [
        new \Componenta\App\ConfigProvider(),
        new \Componenta\Http\Router\ConfigProvider(),
        new \Componenta\Http\Router\App\ConfigProvider(),
        static fn (): array => [ConfigKey::ROUTES_FILE => 'routes.php'],
    ]);
}

function fileRoutesWithoutDiscoveryContainer(string $root, string $environment = 'production'): ContainerValue
{
    $composition = ConfigFactory::create(
        new PathResolver($root),
        fileRoutesWithoutDiscoveryDefinition(),
        new Environment(['APP_ENV' => $environment]),
    );

    return (new ContainerFactory())->create($composition->config, $composition->dependencies);
}

beforeEach(function (): void {
    $this->fileRoutesRoot = sys_get_temp_dir() . '/route_no_discovery_' . bin2hex(random_bytes(8));
    mkdir($this->fileRoutesRoot);
    file_put_contents($this->fileRoutesRoot . '/routes.php', <<<'PHP'
<?php
$routes->addRoute(\Componenta\Http\Router\RouteRecord::get(
    'file', ($prefix ?? '/file') . '/{id}', 'FileOnlyHandler',
));
PHP);
});

afterEach(function (): void {
    BuildFixture::remove($this->fileRoutesRoot);
});

it('resolves configured file routes when application discovery is disabled', function (string $environment): void {
    $container = fileRoutesWithoutDiscoveryContainer($this->fileRoutesRoot, $environment);
    expect($container->has(AppConfigKey::DISCOVERY_SOURCE))->toBeFalse()
        ->and($container->has(ClassIteratorInterface::class))->toBeFalse();

    $routes = $container->get(RouteLocatorInterface::class)->getRoutes();

    expect($routes)->not->toBeInstanceOf(CompiledRoutes::class)
        ->and($routes->match($routes, '/file/42', 'GET')->parameters)->toBe(['id' => 42])
        ->and($routes->generate($routes, 'file', ['id' => 7]))->toBe('/file/7');
})->with(['development', 'production']);

it('prepares file routes without discovery and retains source access for explicit route context', function (): void {
    $paths = new PathResolver($this->fileRoutesRoot);
    ApplicationBuildRunner::build(
        $paths,
        fileRoutesWithoutDiscoveryDefinition(),
        new Environment(['APP_ENV' => 'production']),
        requiredArtifacts: [RouteBuilder::ARTIFACT_ID],
    );
    $container = fileRoutesWithoutDiscoveryContainer($this->fileRoutesRoot);
    expect($container->has(AppConfigKey::PREPARED_BUILD))->toBeTrue()
        ->and($container->has(AppConfigKey::DISCOVERY_SOURCE))->toBeFalse();
    $locator = $container->get(RouteLocatorInterface::class);
    $prepared = $locator->getRoutes();

    expect($prepared)->toBeInstanceOf(CompiledRoutes::class)
        ->and($prepared->match($prepared, '/file/42', 'GET')->parameters)->toBe(['id' => 42])
        ->and($prepared->generate($prepared, 'file', ['id' => 7]))->toBe('/file/7');

    $contextual = $locator->getRoutes(['prefix' => '/custom']);
    expect($contextual)->not->toBeInstanceOf(CompiledRoutes::class)
        ->and($contextual->match($contextual, '/custom/42', 'GET')->parameters)->toBe(['id' => 42])
        ->and($contextual->generate($contextual, 'file', ['id' => 7]))->toBe('/custom/7')
        ->and($locator->getRoutes())->toBe($prepared);
});
