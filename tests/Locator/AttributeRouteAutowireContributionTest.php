<?php

declare(strict_types=1);

use Componenta\DI\Compile\Autowire\AutowireEntry;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\Attribute\Route;
use Componenta\Http\Router\Locator\RouteLocator;
use Componenta\Tokenizer\ClassInfo;

final readonly class AttributeRouteActionInputForTest {}

final readonly class AttributeRouteControllerForTest
{
    #[Route('compiled.action', '/compiled-action')]
    public function action(AttributeRouteActionInputForTest $input): void {}
}

it('contributes route controllers and concrete action parameters as factory roots', function (): void {
    $routesFile = tempnam(sys_get_temp_dir(), 'router-autowire-');
    expect($routesFile)->toBeString();
    file_put_contents($routesFile, "<?php\n\ndeclare(strict_types=1);\n");

    try {
        $locator = new AttributeRouteLocator(new RouteLocator($routesFile));
        $locator->handle(new ClassInfo(AttributeRouteControllerForTest::class));

        expect(array_map(
            static fn (AutowireEntry $entry): string => $entry->class,
            iterator_to_array($locator->entries()),
        ))->toBe([
            AttributeRouteActionInputForTest::class,
            AttributeRouteControllerForTest::class,
        ]);

        $locator->finalize();
        $routes = $locator->getRoutes();

        expect($routes->match($routes, '/compiled-action', 'GET')->handler->value)
            ->toBe(AttributeRouteControllerForTest::class . '::action');
    } finally {
        @unlink($routesFile);
    }
});
