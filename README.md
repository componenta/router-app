# Componenta Router App

Registers attribute routes, HTTP routing middleware, intercepted route handlers
and the `router:list` command. Requires App 6, Config 4, DI 6, Router 4 and PHP 8.5.13+.

Register both `Componenta\Http\Router\ConfigProvider` and
`Componenta\Http\Router\App\ConfigProvider` through the usual provider list.

## Source and build

`AttributeRouteLocator` combines the configured PHP route file with the existing
attribute extraction logic. It receives the shared `app.discovery.source`
iterator through DI and reads it when routes are requested.

When application discovery is disabled, the locator loads the configured route
file without a class iterator. File-only routing supports the same build,
prepared-runtime and explicit-context source paths.

`RouteBuilder` implements `ApplicationBuilderInterface` and is registered in
`app.builders`. Run the ordinary `app:build` command to write its artifact.
Resolving the command or displaying help does not create the builder or scan
classes for the build. Runtime never launches a build.

The builder receives the original locator and the configured route compiler.
`build(BuildContext $build)` compiles Router's data and writes the `router.routes`
artifact with `RouteCacheGenerator::CACHE_VERSION` as its schema version. App owns
serialization, staging, the generation manifest and publication of the complete
build. An unsupported compiler, custom attribute/syntax, or non-exportable value
calls `skip()` with a reason. That generation deliberately uses source routes;
it never reuses a route artifact from the previous generation. Other source,
compiler and storage errors abort publication.

`app:clean` unpublishes the current application build. A running application keeps
its selected immutable generation until its next bootstrap.

The map captures the source inputs at build time. Re-run `app:build` after changing
route definitions, configuration or external values used by the route file.
Development and production are compared using the same inputs and a map built
from those inputs.

## Runtime

By default, development and `APP_DISCOVERY_SOURCE=1` use the full source locator. Production
reads `router.routes` from the `PreparedBuild` selected by App at bootstrap, when
`ConfigKey::COMPILED_PIPELINE` is enabled. Missing, skipped, incompatible or
malformed optional payloads use `app.discovery.source` and the configured route file.
If the runtime deployment policy requires `router.routes`, the locator uses the prepared map
regardless of `APP_ENV` and rejects a missing or invalid map instead of falling back.
Disabling preparation explicitly conflicts with that policy. Explicit context supplied to
`getRoutes($context)` still requests source routes, without changing the normal runtime selection.
The locator chooses prepared or source mode once for its normal runtime path.

App exposes the optional selected snapshot under `App\ConfigKey::PREPARED_BUILD`;
checking this string service ID avoids DI's class autowiring fallback. Runtime
readers never open independent artifact paths or combine different generations.

Public route records preserve the original path, tokens and defaults.
Both matchers report the complete, sorted set of allowed methods for overlapping
static and dynamic routes.

Custom route attributes, custom compilers/syntax configurations, stateful
callables and non-exportable values keep source resolution. Source exceptions
propagate normally.

The intercepted handler resolver obtains the application's `Responder` lazily.
Handlers and callbacks returning a PSR response, middleware or request handler
work without configuring its HTTP factories. A result that needs normalization
uses the registered responder, including an already lazy instance. Configuration
and response creation errors propagate when that dependency is first used. This
behavior is the same for source and prepared routes.

## Configuration

```php
use Componenta\Http\Router\ConfigKey;

return [
    ConfigKey::ROUTES_FILE => 'config/routes.php',
    ConfigKey::COMPILED_PIPELINE => true,
];
```

App owns the build directory and deployment identity (`APP_BUILD_ID`).
`ROUTES_CACHE_FILE` remains a standalone Router setting; this App adapter does
not use it. Its `router.routes` artifact cannot be redirected to a source file.

## Migration

Remove registrations of `RouteCacheCompiler` and route autowire contributors.
The ConfigProvider now registers `RouteBuilder`; it no longer registers the
locator as a mandatory discovery listener. Regenerate the application build with
`app:build` after upgrading. Remove independent router cache files from the old
deployment. The builder constructor now accepts only its source locator and
cache generator. `build()` requires `BuildContext`; individual `clean()` and
`RouteLocatorFactory::cacheFile()` APIs have been removed. Cleanup belongs to
App's `BuildStore`. A manually constructed `PreparedRouteLocator` receives a
selected `PreparedBuild` and its source closure.

The base router config provider registers the route handler resolver slot exactly once. Load the router-app provider after the base router provider (as for the route locator); it replaces the slot alias with the intercepted resolver rather than appending a competing resolver to the middleware chain.

Repeated builds reload file routes using the same discovery snapshot and do not mutate the runtime locator's existing collection. The router cache generator is the owner of the supported preparation contract; unsupported compiler or syntax configurations fall back to the source. Genuine route-definition errors still fail the build.
