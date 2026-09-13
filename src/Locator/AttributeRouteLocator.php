<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Locator;

use Componenta\ClassFinder\Attribute\ListenTo;
use Componenta\ClassFinder\ClassListenerInterface;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\ClassFinder\Exception\ListenerAlreadyFinalizedException;
use Componenta\ClassFinder\FinalizableListenerInterface;
use Componenta\ClassFinder\FinalizationStateInterface;
use Componenta\Http\Router\Attribute\Route;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\Exception\RouteAlreadyExistsException;
use Componenta\Http\Router\Locator\RouteLocator;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use Componenta\Reflection\Reflection;
use Componenta\Tokenizer\ClassInfo;

/**
 * Attribute-based route locator
 */
#[ListenTo(Route::class, deepSearch: true)]
final class AttributeRouteLocator implements RouteLocatorInterface, ClassListenerInterface, FinalizableListenerInterface, FinalizationStateInterface
{
    private ?Routes $routes = null;
    private bool $isFinalized = false;

    public function canOptimize(): bool
    {
        foreach ($this->attributes as [, $route]) {
            if ($route::class !== Route::class) { return false; }
        }
        return true;
    }

    public bool $finalized {
        get => $this->isFinalized;
    }

    /**
     * @var array<int, array{0:string, 1: Route}>
     */
    private array $attributes = [];

    public function __construct(
        private readonly RouteLocator $locator,
        private readonly ?ClassIteratorInterface $source = null,
    ) {
    }

    public function getRoutes(array $context = []): RouteCollectorInterface
    {
        if (!$this->isFinalized && $this->source !== null) {
            foreach ($this->source as $info) {
                if ($info->isClass || $info->isEnum) { $this->handle($info); }
            }
            $this->finalize($context);
        }
        if (!$this->routes) {
            $this->routes = $this->locator->getRoutes($context);
        }

        return $this->routes;
    }

    public function handle(ClassInfo $info): void
    {
        $result = Reflection::getDeepMetadata($info->reflector, Route::class);

        if ($result !== []) {
            foreach ($result as $target => $attributes) {
                $this->attributes[] = [self::normalizeTarget($target), $attributes[0]];
            }
        }
    }

    private static function normalizeTarget(string $target): string
    {
        return str_ends_with($target, '()') ? substr($target, 0, -2) : $target;
    }

    public function finalize(array $context = []): void
    {
        if ($this->isFinalized) {
            throw ListenerAlreadyFinalizedException::forListener($this);
        }

        usort($this->attributes, static fn(array $a, array $b): int => $b[1]->priority <=> $a[1]->priority);

        $routes = $this->locator->getRoutes($context);

        $seen = [];

        foreach ($this->attributes as $attribute) {
            [$target, $route] = $attribute;

            $record = new RouteRecord(
                $route->name,
                $route->path,
                $target,
                $route->methods,
                $route->middlewares,
                $route->tokens,
                $route->defaults,
                $route->group
            );

            $fingerprint = $record->toArray();

            if (isset($seen[$record->name])) {
                if ($seen[$record->name] === $fingerprint) {
                    continue;
                }

                throw new RouteAlreadyExistsException($record->name);
            }

            $seen[$record->name] = $fingerprint;
            $routes->addRoute($record);
        }
        $this->routes = $routes;
        $this->isFinalized = true;
    }
}
