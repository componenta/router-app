# Componenta Router App

Registers attribute routes, HTTP routing middleware, intercepted route handlers
and the `router:list` command. Requires App 6, Config 4, DI 6, Router 4 and PHP 8.5.13+.

Register both `Componenta\Http\Router\ConfigProvider` and
`Componenta\Http\Router\App\ConfigProvider` through the usual provider list.

## Source and build

`AttributeRouteLocator` combines the configured PHP route file with the existing
attribute extraction logic. It receives the shared `app.discovery.source`
iterator through DI and reads it when routes are requested.

`RouteBuilder` implements `ApplicationBuilderInterface` and is registered in
`app.builders`. Run the ordinary `app:build` command to write its artifact.
Resolving the command or displaying help does not create the builder or scan
classes for the build. Runtime never launches a build.

The builder receives the original locator and the configured route compiler.
It uses App's shared PhpMapFile writer, tied to app.build.generation. An unsupported optimization publishes an empty router payload, replacing any earlier map so old routes cannot remain active after a rebuild. app:clean removes the owned artifact via the same utility.

The map captures the source inputs at build time. Re-run `app:build` after changing
route definitions, configuration or external values used by the route file.
Development and production are compared using the same inputs and a map built
from those inputs.

## Runtime

Development and APP_DISCOVERY_SOURCE=1 use the full source locator. Production uses a map only if its generation matches app.discovery.generation and its Router payload validates. Missing, incompatible, wrong-generation or malformed maps fall back to app.discovery.source and the configured route file. Explicit context supplied to getRoutes($context) also bypasses the map.

Public route records preserve the original path, tokens and defaults.
Both matchers report the complete, sorted set of allowed methods for overlapping
static and dynamic routes.

Custom route attributes, custom compilers/syntax configurations, stateful
callables and non-exportable values keep source resolution. Source exceptions
propagate normally.

## Configuration

```php
use Componenta\Http\Router\ConfigKey;

return [
    ConfigKey::ROUTES_FILE => 'config/routes.php',
    ConfigKey::ROUTES_CACHE_FILE => 'var/cache/routes.php',
    ConfigKey::COMPILED_PIPELINE => true,
];
```

The cache filename has no required substring. When omitted, it is derived by
inserting `.cache` before the source file extension. A cache path must differ
from its source.

## Migration

Remove registrations of `RouteCacheCompiler` and route autowire contributors.
The ConfigProvider now registers `RouteBuilder`; it no longer registers the
locator as a mandatory discovery listener. Regenerate the route cache with
`app:build` after upgrading.
