<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Build;

use Componenta\App\Build\ApplicationBuilderInterface;
use Componenta\App\Build\BuildContext;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\Exception\UnsupportedRouteCacheException;
use Componenta\VarExport\Exception\ExceptionInterface as ExportException;

final readonly class RouteBuilder implements ApplicationBuilderInterface
{
    public const string ARTIFACT_ID = 'router.routes';

    public function __construct(
        private AttributeRouteLocator $source,
        private RouteCacheGenerator $generator,
    ) {}

    public function build(BuildContext $build): void
    {
        $routes = $this->source->loadRoutes();
        if (!$this->source->canOptimize()) {
            $build->skip(self::ARTIFACT_ID, 'Custom route attributes require the original route source.');
            return;
        }

        try {
            $data = $this->generator->compile($routes);
            $build->write(self::ARTIFACT_ID, $data, RouteCacheGenerator::CACHE_VERSION);
        } catch (UnsupportedRouteCacheException|ExportException $error) {
            // Unsupported optimization replaces the previous artifact in this build.
            // Source and compiler errors other than these exceptions still abort it.
            $build->skip(self::ARTIFACT_ID, 'Route preparation is unsupported: ' . $error->getMessage());
        }
    }
}
