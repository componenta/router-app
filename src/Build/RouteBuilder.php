<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Build;

use Componenta\App\Build\ApplicationBuilderInterface;
use Componenta\App\Build\ApplicationBuildCleanerInterface;
use Componenta\App\Build\PhpMapFile;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\Exception\UnsupportedRouteCacheException;
use Componenta\VarExport\Exception\ExceptionInterface as ExportException;

final readonly class RouteBuilder implements ApplicationBuilderInterface, ApplicationBuildCleanerInterface
{
    public function __construct(
        private AttributeRouteLocator $source,
        private string $file,
        private RouteCacheGenerator $generator,
        private ?string $generation = null,
    ) {}

    public function clean(): void
    {
        PhpMapFile::remove($this->file);
    }

    public function build(): void
    {
        $routes = $this->source->loadRoutes();
        if (!$this->source->canOptimize()) {
            PhpMapFile::write($this->file, [], $this->generation);
            return;
        }

        try {
            $data = $this->generator->compile($routes);
            // App owns trusted PHP map publication. Router owns its payload.
            PhpMapFile::write($this->file, $data, $this->generation);
        } catch (UnsupportedRouteCacheException|ExportException) {
            // Stateful objects and closures retain the complete source path.
            // Replace the old map with an invalid router payload in this generation.
            PhpMapFile::write($this->file, [], $this->generation);
        }
    }
}
