<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Locator;

use Closure;
use RuntimeException;
use Componenta\App\Build\PreparedBuild;
use Componenta\Http\Router\App\Build\RouteBuilder;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;

/** Uses routing data from the application's selected build snapshot. */
final class PreparedRouteLocator implements RouteLocatorInterface
{
    private ?CompiledRoutes $routes = null;
    private bool $checked = false;

    /** @param Closure(): RouteLocatorInterface $source */
    public function __construct(
        private readonly PreparedBuild $prepared,
        private readonly Closure $source,
    ) {}

    public function getRoutes(array $context = []): RouteCollectorInterface
    {
        if ($context !== []) {
            return ($this->source)()->getRoutes($context);
        }

        // Select prepared or source mode once for this application runtime.
        if (!$this->checked) {
            $this->routes = CompiledRoutes::tryFromArray(
                $this->prepared->read(RouteBuilder::ARTIFACT_ID, RouteCacheGenerator::CACHE_VERSION),
            );
            if ($this->routes === null && $this->prepared->isRequired(RouteBuilder::ARTIFACT_ID)) {
                throw new RuntimeException(sprintf('Required build artifact "%s" contains an invalid route map.', RouteBuilder::ARTIFACT_ID));
            }
            $this->checked = true;
        }

        return $this->routes ?? ($this->source)()->getRoutes();
    }
}
