<?php

declare(strict_types=1);

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Http\Router\App\Build\RouteBuilder;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\App\Tests\BuildFixture;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\Locator\RouteLocator;

it('cleans the published build centrally without invalidating an existing route snapshot', function (): void {
    $root = sys_get_temp_dir() . '/router_cleanup_' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    file_put_contents($root . '/source.php', '<?php');
    $builder = new RouteBuilder(
        new AttributeRouteLocator(new RouteLocator($root . '/source.php', useCache: false), new ClassIterator([])),
        new RouteCacheGenerator(),
    );
    $store = BuildFixture::store($root);
    $orchestrator = new ApplicationBuildOrchestrator([$builder], $store);
    try {
        $orchestrator->clean();
        expect(is_dir($root . '/build'))->toBeFalse();
        $prepared = $orchestrator->build();
        $orchestrator->clean();
        $orchestrator->clean();
        expect($store->load())->toBeNull()
            ->and($prepared->read(RouteBuilder::ARTIFACT_ID, RouteCacheGenerator::CACHE_VERSION))->not->toBeNull()
            ->and(file_get_contents($root . '/source.php'))->toBe('<?php');
        expect($orchestrator->build()->generation)->not->toBe($prepared->generation);
    } finally {
        BuildFixture::remove($root);
    }
});
