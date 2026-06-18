<?php

declare(strict_types=1);

use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\ClassFinder\Exception\ListenerAlreadyFinalizedException;
use Componenta\Http\Router\Attribute\Route;
use Componenta\Http\Router\Exception\RouteAlreadyExistsException;
use Componenta\Http\Router\Locator\RouteLocator;
use Componenta\Tokenizer\ClassInfo;

#[Route('low_priority', '/{value}', 'GET', tokens: ['value' => '[a-z]+'], priority: 0)]
final readonly class AttributeRouteLocatorLowPriorityController {}

#[Route('high_priority', '/{slug}', 'GET', tokens: ['slug' => '[a-z]+'], priority: 10)]
final readonly class AttributeRouteLocatorHighPriorityController {}

#[Route('conflicting_route', '/first', 'GET')]
final readonly class AttributeRouteLocatorFirstConflictController {}

#[Route('conflicting_route', '/second', 'GET')]
final readonly class AttributeRouteLocatorSecondConflictController {}

function routerAppRoutesFile(): string
{
    $file = tempnam(sys_get_temp_dir(), 'router-app-routes-');

    expect($file)->toBeString();

    file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n");

    return $file;
}

describe('AttributeRouteLocator', function () {
    it('adds higher priority attribute routes before lower priority routes', function () {
        $routesFile = routerAppRoutesFile();
        $locator = new AttributeRouteLocator(new RouteLocator($routesFile));

        try {
            $locator->handle(new ClassInfo(AttributeRouteLocatorLowPriorityController::class));
            $locator->handle(new ClassInfo(AttributeRouteLocatorHighPriorityController::class));
            $locator->finalize();

            $routes = $locator->getRoutes();

            expect($routes->match($routes, '/abc', 'GET')->name)->toBe('high_priority');
        } finally {
            @unlink($routesFile);
        }
    });

    it('ignores exact duplicate attribute notifications', function () {
        $routesFile = routerAppRoutesFile();
        $locator = new AttributeRouteLocator(new RouteLocator($routesFile));

        try {
            $locator->handle(new ClassInfo(AttributeRouteLocatorLowPriorityController::class));
            $locator->handle(new ClassInfo(AttributeRouteLocatorLowPriorityController::class));
            $locator->finalize();

            $routes = $locator->getRoutes();

            expect($routes->count())->toBe(1)
                ->and($routes->match($routes, '/abc', 'GET')->name)->toBe('low_priority');
        } finally {
            @unlink($routesFile);
        }
    });

    it('rejects repeated finalization', function () {
        $routesFile = routerAppRoutesFile();
        $locator = new AttributeRouteLocator(new RouteLocator($routesFile));

        try {
            $locator->finalize();

            expect(fn () => $locator->finalize())
                ->toThrow(ListenerAlreadyFinalizedException::class, AttributeRouteLocator::class);
        } finally {
            @unlink($routesFile);
        }
    });

    it('keeps rejecting conflicting duplicate route names', function () {
        $routesFile = routerAppRoutesFile();
        $locator = new AttributeRouteLocator(new RouteLocator($routesFile));

        try {
            $locator->handle(new ClassInfo(AttributeRouteLocatorFirstConflictController::class));
            $locator->handle(new ClassInfo(AttributeRouteLocatorSecondConflictController::class));

            $locator->finalize();
        } finally {
            @unlink($routesFile);
        }
    })->throws(RouteAlreadyExistsException::class);
});
