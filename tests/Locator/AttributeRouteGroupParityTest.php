<?php

declare(strict_types=1);

use Componenta\ClassFinder\Exception\ListenerAlreadyFinalizedException;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\Attribute\Route;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Locator\RouteLocator;
use Componenta\Tokenizer\ClassInfo;

final class GroupedAttributeRoutesFixture
{
    #[Route('index', '/index', group: 'public')]
    public function publicIndex(): void {}

    #[Route('index', '/index', group: 'admin')]
    public function adminIndex(): void {}
}

it('checks uniqueness of effective route names after applying attribute groups', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'router_groups_');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    try {
        file_put_contents($file, '<?php $routes->group("public", "/public"); $routes->group("admin", "/admin", middleware: ["auth"]);');
        $locator = new AttributeRouteLocator(new RouteLocator($file, useCache: false));
        $info = new ClassInfo(GroupedAttributeRoutesFixture::class);
        $locator->handle($info);
        $locator->handle($info);
        $locator->finalize();
        $source = $locator->getRoutes();
        $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
        foreach ([$source, $prepared] as $routes) {
            expect($routes->match($routes, '/public/index', 'GET')->name)->toBe('public.index')
                ->and($routes->match($routes, '/admin/index', 'GET')->name)->toBe('admin.index')
                ->and($routes->match($routes, '/admin/index', 'GET')->middlewares?->toArray())->toBe(['auth']);
        }
        expect(fn () => $locator->handle($info))->toThrow(ListenerAlreadyFinalizedException::class);
    } finally { unlink($file); }
});
