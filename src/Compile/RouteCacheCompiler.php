<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Compile;

use Componenta\ClassFinder\Compile\CompileResult;
use Componenta\ClassFinder\Compile\ListenerCompilerInterface;
use Componenta\Config\Config;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Factory\RouteLocatorFactory;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\VarExport\Export;
use RuntimeException;

/**
 * Snapshots the routes collected by {@see AttributeRouteLocator} into a
 * sidecar cache file that the production {@see RouteLocatorFactory}
 * auto-detects in place of the attribute scanner.
 *
 * The compiler emits no config entry - the file itself is the contract,
 * identified by the naming convention `<routes.php>` -> `<routes.cache.php>`
 * (see {@see RouteLocatorFactory::cacheFileFor()}).
 *
 * CLI-gated: the generated file is a deploy artifact consumed by prod.
 * `app:build` (CLI) runs with `APP_ENV=development` to scan
 * attributes, so an `APP_ENV` check can't distinguish that legitimate
 * write from an incidental dev-HTTP invocation. `PHP_SAPI` can -
 * compilation only happens off the command line. Dev HTTP invocations
 * (if {@see DiscoveryCompiler} is ever wired into a runtime code path)
 * short-circuit, keeping the committed `routes.cache.php` stable.
 */
final readonly class RouteCacheCompiler implements ListenerCompilerInterface
{
    public function __construct(
        private Config $config,
        private ?PathResolverInterface $paths = null,
        private RouteCacheGenerator $generator = new RouteCacheGenerator(),
    ) {}

    public function supports(object $listener): bool
    {
        return $listener instanceof AttributeRouteLocator;
    }

    public function compile(object $listener, string $cacheDir): CompileResult
    {
        if (PHP_SAPI !== 'cli') {
            return CompileResult::empty();
        }

        /** @var AttributeRouteLocator $listener */
        $routesFile = $this->config->get(ConfigKey::ROUTES_FILE, default: null);

        if (!is_string($routesFile) || trim($routesFile) === '') {
            throw new RuntimeException(sprintf(
                'Cannot compile routes: config key %s is not set.',
                ConfigKey::ROUTES_FILE,
            ));
        }

        // Respect explicit override first (e.g. app-level cache layout),
        // otherwise fall back to the legacy derived-path convention.
        $resolvedRoutesFile = $this->paths?->resolve($routesFile) ?? $routesFile;
        $cacheFile = $this->config->get(ConfigKey::ROUTES_CACHE_FILE, default: null);
        if ($cacheFile !== null && (!is_string($cacheFile) || trim($cacheFile) === '')) {
            throw new RuntimeException(sprintf(
                'Cannot compile routes: config key %s must be a non-empty string.',
                ConfigKey::ROUTES_CACHE_FILE,
            ));
        }

        $cacheFile = $cacheFile === null
            ? RouteLocatorFactory::cacheFileFor($resolvedRoutesFile)
            : ($this->paths?->resolve($cacheFile) ?? $cacheFile);
        $compiled  = $this->generator->compile($listener->getRoutes());

        return CompileResult::filesOnly([
            $cacheFile => "<?php\n\ndeclare(strict_types=1);\n\nreturn " . Export::pretty($compiled) . ";\n",
        ]);
    }
}
