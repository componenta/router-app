<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Locator;

use Closure;
use Componenta\App\Build\PhpMapFile;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;

/** A prepared route map is usable only in the selected App discovery generation. */
final class PreparedRouteLocator implements RouteLocatorInterface
{
    private ?CompiledRoutes $routes = null;
    private bool $checked = false;

    /** @param Closure(): RouteLocatorInterface $source */
    public function __construct(
        private readonly string $file,
        private readonly string $generation,
        private readonly Closure $source,
    ) {}

    public function getRoutes(array $context = []): RouteCollectorInterface
    {
        if ($context !== []) {
            return ($this->source)()->getRoutes($context);
        }

        // Select prepared or source mode once for this application runtime.
        if (!$this->checked) {
            $this->routes = CompiledRoutes::tryFromArray(PhpMapFile::read($this->file, $this->generation));
            $this->checked = true;
        }

        return $this->routes ?? ($this->source)()->getRoutes();
    }
}
