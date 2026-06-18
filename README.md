# Componenta Router App

Application integration for `componenta/router`. The package adds `#[Route]` discovery, connects route cache generation to application cache compilation, registers the route locator as a class-finder listener, and provides route handler resolution through HTTP interceptors.

Runtime code that only matches routes, generates URLs, or dispatches route handlers without HTTP interceptors should depend on `componenta/router`. `componenta/router-app` belongs in application assembly code: it connects the router with class discovery, application cache compilation, and the interceptor pipeline.

## Dependencies

PHP:

| Requirement | Version |
|---|---|
| PHP | `^8.4` |

Packages:

| Package | Purpose |
|---|---|
| `componenta/app` | Application boot cycle and discovery notification. |
| `componenta/app-http` | HTTP boot target that loads the application pipeline file. |
| `componenta/router` | Route records, matching, URL generation, dispatch middleware, and route cache loading. |
| `componenta/class-finder` | Class scanning and listener notification. |
| `componenta/config` | Shared configuration object and router config keys. |
| `componenta/di` | Factory and autowire registration through `ConfigProvider`. |
| `componenta/http-responder` | Optional `Responder` for non-PSR-7 handler results. |
| `componenta/interceptor` | HTTP interceptor pipeline for `InterceptedRouteHandlerResolver`. |
| `componenta/middleware-factory` | Middleware resolver registration for route handlers. |
| `componenta/path-resolver` | Resolving route file paths relative to the application root. |
| `componenta/reflection` | Reading `#[Route]` metadata from classes and inherited declarations. |
| `componenta/tokenizer` | Class information passed by the scanner. |
| `componenta/var-export` | Exporting compiled route arrays into PHP cache files. |
| `psr/container` | Retrieving dependencies from the container. |
| `psr/http-message` | Response contracts used by route handlers. |
| `psr/http-server-middleware` | Middleware contracts used by route dispatch. |

Install:

```bash
composer require componenta/router-app
```

Register this provider after the runtime router provider. The order matters because `router-app` replaces the `RouteLocatorInterface` factory with the integration factory:

```php
return [
    new Componenta\Http\Router\ConfigProvider(),
    new Componenta\Http\Router\App\ConfigProvider(),
];
```

## Registered Services

`Componenta\Http\Router\App\ConfigProvider` adds:

| Service or key | Registration |
|---|---|
| `RouteLocatorInterface` | `router-app` `RouteLocatorFactory`, which selects the development or production locator. |
| `InterceptedRouteHandlerResolver` | Factory for HTTP-interceptor route handler resolution. |
| `RouteCacheCompiler` | Autowired route cache compiler. |
| `RouterListCommand` | Autowired Symfony Console command for listing registered routes. |
| `AppConfigKey::BOOTLOADERS` | Adds `RoutingBootloader` for HTTP applications. |
| `ClassFinderConfigKey::LISTENERS` | Adds `AttributeRouteLocator` so `#[Route]` attributes are collected during discovery. |
| `CompileConfigKey::LISTENER_COMPILERS` | Adds `RouteCacheCompiler` to the `class-finder` listener compiler list. |
| `MiddlewareConfigKey::RESOLVERS` | Adds `InterceptedRouteHandlerResolver` to the middleware resolver list. |
| `ConsoleConfigKey::COMMANDS` | Adds `router:list` to the console command graph. |
| `RouterConfigKey::ROUTES_FILE` | Sets the default route file to `config/routes.php`. |

Route records, `Router`, `Routes`, `MatchRouteMiddleware`, `DispatchRouteMiddleware`, `RouteHandlerResolver`, `CompilerInterface`, `MatcherInterface`, and `GeneratorInterface` stay in `componenta/router`.

## HTTP Pipeline

`RoutingBootloader` runs only for the HTTP application scope. During boot it adds routing middleware to the HTTP boot target with priority `50`:

```php
$app->pipe(Componenta\Http\Router\Middleware\MatchRouteMiddleware::class, priority: 50);
$app->pipe(Componenta\Http\Router\Middleware\DispatchRouteMiddleware::class, priority: 50);
```

Applications that register this provider do not add these two middleware manually. Put middleware that must run before routing, such as error handling or body parsing, into `config/pipeline.php` with a higher priority, for example `100`.

## Route Discovery

`AttributeRouteLocator` implements `RouteLocatorInterface`, `ClassListenerInterface`, and `FinalizableListenerInterface`. In development mode, `RouteLocatorFactory` returns this locator.

Flow:

1. `RouteLocatorFactory` reads `ConfigKey::ROUTES_FILE`, resolves it through `PathResolverInterface`, and creates a base `RouteLocator`.
2. `AttributeRouteLocator` loads explicit routes from that route file.
3. During scanning, `class-finder` passes discovered classes to `AttributeRouteLocator::handle()`.
4. The locator reads `#[Route]` metadata through `Reflection::getDeepMetadata()`.
5. After scanning, `finalize()` sorts discovered attributes by `priority` from highest to lowest and adds them as `RouteRecord` instances.

Example:

```php
use Componenta\Http\Router\Attribute\Route;
use Psr\Http\Message\ResponseInterface;

#[Route(
    name: 'posts.show',
    path: '/posts/[id:\d+]',
    methods: 'GET',
    middlewares: ['web'],
)]
final readonly class ShowPostController
{
    public function __invoke(int $id): ResponseInterface
    {
        // ...
    }
}
```

The attribute only describes the route. Middleware and handler execution are owned by `componenta/router` and `componenta/middleware-factory`.

`methods` accepts a string (`'GET'`), a `|`-separated string (`'GET|POST'`), or an array (`['GET', 'POST']`). When multiple attribute routes can match the same URI, the route with the higher `priority` is added first.

## Route File

`ConfigKey::ROUTES_FILE` remains the route entry file even when attributes are enabled. This package sets its default value to `config/routes.php`. Use that file for manual routes, groups, and shared route setup, or override the config key when the application keeps routes somewhere else.

```php
use Componenta\Http\Router\Routes;

/** @var Routes $routes */

$api = $routes->group('api', '/api', middleware: ['api']);
$api->get('health', '/health', HealthController::class);
```

In development, attribute routes are added to routes from this file. In production, the application should read the compiled route cache.

## Development And Production

`router-app` `RouteLocatorFactory` selects the locator by environment:

| Condition | Factory result |
|---|---|
| `APP_ENV=production` | Base `componenta/router` `RouteLocatorFactory`. It reads cache when cache use is enabled and the cache file exists. |
| Any other environment | `AttributeRouteLocator`, which loads the route file and appends discovered attribute routes. |

The base `RouteLocatorFactory` uses cache only when `ConfigKey::COMPILED_PIPELINE` is enabled and the cache file exists.

## Route Cache Compilation

`RouteCacheCompiler` is registered through `CompileConfigKey::LISTENER_COMPILERS`. It supports only `AttributeRouteLocator`.

`compile()`:

- runs only in CLI;
- requires `ConfigKey::ROUTES_FILE`;
- uses `ConfigKey::ROUTES_CACHE_FILE` when it is configured;
- otherwise derives the cache path from the route file, for example `routes.php` -> `routes.cache.php`;
- compiles the current route collection through `RouteCacheGenerator`;
- returns `CompileResult::filesOnly()` so the cache builder can write the PHP cache file.

Configuration example:

```php
use Componenta\Http\Router\ConfigKey;

return [
    ConfigKey::ROUTES_FILE => 'config/routes.php',
    ConfigKey::ROUTES_CACHE_FILE => 'var/cache/router/routes.cache.php',
    ConfigKey::COMPILED_PIPELINE => true,
];
```

## Console Commands

When `componenta/app-console` is installed, this package contributes `router:list` through `Componenta\App\Console\ConfigKey::COMMANDS`.

```bash
php bin/console.php router:list
```

The command resolves `RouteLocatorInterface` from the container and lists method, path, route name, group, and handler. It uses the same locator as the running application, so development mode includes routes from `config/routes.php` and discovered `#[Route]` attributes, while production mode uses the production locator and route cache behavior.

## HTTP Interceptors

`InterceptedRouteHandlerResolver` lives in `componenta/router-app` because it integrates routing with the application HTTP interceptor pipeline. The base `componenta/router` package dispatches handlers directly through `RouteHandlerResolver` and does not depend on `componenta/interceptor`.

The provider registers it in the `componenta/middleware-factory` resolver list by default:

```php
use Componenta\Http\Middleware\ConfigKey as MiddlewareConfigKey;
use Componenta\Http\Router\App\Resolver\InterceptedRouteHandlerResolver;

return [
    MiddlewareConfigKey::RESOLVERS => [
        InterceptedRouteHandlerResolver::class,
    ],
];
```

`InterceptedRouteHandlerResolverFactory` reads:

| Dependency | Source |
|---|---|
| `CallableExecutorInterface` | Container. |
| `PipelineInterface` | Container. Registered by `componenta/interceptor`. |
| `Responder` | Container, when registered. |

If `Responder` is not registered, route handlers must return `ResponseInterface`, `MiddlewareInterface`, or `RequestHandlerInterface`; other values fail during dispatch.

The HTTP interceptor pipeline is created by `componenta/interceptor`. Its factory always adds `ParameterResolvingInterceptor`, then appends interceptor service ids from `Componenta\Interceptor\ConfigKey::HTTP_INTERCEPTORS`. A typical HTTP application adds `AttributeInterceptor::class` to that list so route handlers can use `#[Intercept]`.

## Package Boundaries

Use `componenta/router` for:

- explicit route registration;
- URI and HTTP-method matching;
- URL generation;
- route groups;
- PSR-15 match and dispatch middleware;
- loading already compiled route cache files.

Use `componenta/router-app` for:

- discovering `#[Route]` in application source code;
- connecting route compilation to application cache compilation;
- dispatching route handlers through HTTP interceptors.
