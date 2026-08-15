<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Locator;

use Componenta\ClassFinder\Attribute\DevOnly;
use Componenta\ClassFinder\Attribute\ListenTo;
use Componenta\ClassFinder\ClassListenerInterface;
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
use Componenta\DI\Compile\Autowire\AutowireEntry;
use Componenta\DI\Compile\Autowire\AutowireEntryContributorInterface;
use Componenta\DI\Resolver\Entry\EntryClassEligibility;
use Componenta\DI\Resolver\TypeHints;
use Componenta\Reflection\Reflection;
use Componenta\Tokenizer\ClassInfo;
use ReflectionClass;
use ReflectionMethod;

/**
 * Attribute-based route locator
 */
#[DevOnly]
#[ListenTo(Route::class, deepSearch: true)]
final class AttributeRouteLocator implements RouteLocatorInterface, ClassListenerInterface, FinalizableListenerInterface, FinalizationStateInterface, AutowireEntryContributorInterface
{
    private ?Routes $routes = null;
    private bool $isFinalized = false;

    public bool $finalized {
        get => $this->isFinalized;
    }

    /**
     * @var array<int, array{0:string, 1: Route}>
     */
    private array $attributes = [];

    public function __construct(
        private readonly RouteLocator $locator
    ) {
    }

    public function getRoutes(array $context = []): RouteCollectorInterface
    {
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

    public function entries(): iterable
    {
        $classes = [];

        foreach ($this->attributes as [$target]) {
            [$class, $method] = array_pad(explode('::', $target, 2), 2, null);
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if (EntryClassEligibility::allows($reflection)) {
                $classes[$reflection->getName()] = true;
            }

            if ($method === null || !$reflection->hasMethod($method)) {
                continue;
            }

            $action = new ReflectionMethod($class, $method);
            foreach ($action->getParameters() as $parameter) {
                $dependency = TypeHints::classOf($parameter->getType(), $parameter->getDeclaringClass());
                if ($dependency !== null && class_exists($dependency)) {
                    $candidate = new ReflectionClass($dependency);
                    if (EntryClassEligibility::allows($candidate)) {
                        $classes[$candidate->getName()] = true;
                    }
                }
            }
        }

        ksort($classes);
        foreach (array_keys($classes) as $class) {
            yield new AutowireEntry($class, 'route discovery');
        }
    }

    public function finalize(): void
    {
        if ($this->isFinalized) {
            throw ListenerAlreadyFinalizedException::forListener($this);
        }

        $this->isFinalized = true;

        usort($this->attributes, static fn(array $a, array $b): int => $b[1]->priority <=> $a[1]->priority);

        $this->getRoutes();

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
            $this->routes->addRoute($record);
        }
    }
}
