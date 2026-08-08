# Componenta Router App

Интеграционный пакет для `componenta/router` в Componenta-приложении. Пакет добавляет обнаружение маршрутов по `#[Route]`, подключает компиляцию маршрутов к сборке кеша приложения, регистрирует загрузчик HTTP-маршрутизации и дает резолвер обработчиков маршрута через HTTP-перехватчики.

Если коду нужно только сопоставить путь с маршрутом, сгенерировать URL или выполнить найденный маршрут без HTTP-перехватчиков, он должен зависеть от `componenta/router`. `componenta/router-app` нужен точке сборки приложения: он связывает роутер с обнаружением классов, кешем приложения и конвейером перехватчиков.

## Зависимости

Версия PHP:

| Требование | Версия |
|---|---|
| PHP | `^8.4` |

Пакеты:

| Пакет | Назначение |
|---|---|
| `componenta/app` | Регистрация загрузчиков приложения. |
| `componenta/app-http` | HTTP-точка загрузки, в которую при старте добавляются промежуточные обработчики маршрутизации. |
| `componenta/router` | Записи маршрутов, сопоставление запроса с маршрутом, генерация URL, выполнение маршрута и кеш маршрутов. |
| `componenta/class-finder` | Сканирование классов и вызов слушателей, которые реагируют на найденные атрибуты. |
| `componenta/config` | Общий объект конфигурации и ключи, используемые роутером. |
| `componenta/di` | Регистрация фабрик и сервисов, которые контейнер собирает автоматически, через `ConfigProvider`. |
| `componenta/http-responder` | `Responder` для результата обработчика, если обработчик вернул не PSR-7 ответ. |
| `componenta/interceptor` | HTTP-конвейер перехватчиков для `InterceptedRouteHandlerResolver`. |
| `componenta/middleware-factory` | Регистрация резолвера промежуточных обработчиков для обработчиков маршрутов. |
| `componenta/path-resolver` | Разрешение путей к файлу маршрутов относительно корня приложения. |
| `componenta/reflection` | Чтение `#[Route]` с класса и родительских объявлений. |
| `componenta/tokenizer` | Информация о найденном классе при сканировании исходников. |
| `componenta/var-export` | Экспорт скомпилированного массива маршрутов в PHP-файл. |
| `psr/container` | Получение зависимостей из контейнера. |
| `psr/http-message` | Контракты ответов, которые используют обработчики маршрутов. |
| `psr/http-server-middleware` | Контракты промежуточных обработчиков, которые использует выполнение маршрута. |

Установка:

```bash
composer require componenta/router-app
```

Регистрируйте провайдер после провайдера базового роутера. Это важно, потому что `router-app` заменяет фабрику `RouteLocatorInterface` на интеграционную:

```php
return [
    new Componenta\Http\Router\ConfigProvider(),
    new Componenta\Http\Router\App\ConfigProvider(),
];
```

## Что регистрирует пакет

`Componenta\Http\Router\App\ConfigProvider` добавляет:

| Сервис или ключ | Что регистрируется |
|---|---|
| `RouteLocatorInterface` | `RouteLocatorFactory` из `router-app`, которая выбирает локатор для разработки или продакшена. |
| `InterceptedRouteHandlerResolver` | Фабрика резолвера обработчиков маршрута через HTTP-перехватчики. |
| `RouteCacheCompiler` | Сервис компиляции кеша маршрутов, который контейнер может собрать автоматически. |
| `CompileConfigKey::LISTENER_COMPILERS` | Добавляет `RouteCacheCompiler` в список компиляторов слушателей `class-finder`. |
| `AppConfigKey::BOOTLOADERS` | Добавляет `RoutingBootloader` для HTTP-приложений. |
| `ClassFinderConfigKey::LISTENERS` | Добавляет `AttributeRouteLocator`, чтобы `#[Route]` собирались во время обнаружения классов. |
| `MiddlewareConfigKey::RESOLVERS` | Добавляет `InterceptedRouteHandlerResolver` в список резолверов промежуточных обработчиков. |
| `RouterConfigKey::ROUTES_FILE` | Задает файл маршрутов по умолчанию: `config/routes.php`. |

Базовые записи маршрутов, `Router`, `Routes`, `MatchRouteMiddleware`, `DispatchRouteMiddleware`, `RouteHandlerResolver`, `CompilerInterface`, `MatcherInterface` и `GeneratorInterface` остаются в `componenta/router`.

## HTTP-загрузка

`RoutingBootloader` работает только в HTTP-области приложения. При старте он добавляет `MatchRouteMiddleware` и `DispatchRouteMiddleware` в HTTP-точку загрузки с приоритетом `50`:

```php
$app->pipe(Componenta\Http\Router\Middleware\MatchRouteMiddleware::class, priority: 50);
$app->pipe(Componenta\Http\Router\Middleware\DispatchRouteMiddleware::class, priority: 50);
```

Приложения, которые используют `componenta/app-http` и регистрируют провайдер этого пакета, не должны добавлять эти два промежуточных обработчика вручную. Обработчики, которые должны выполняться раньше маршрутизации, например обработка ошибок или разбор тела запроса, регистрируйте в `config/pipeline.php` с более высоким приоритетом, например `100`.

## Обнаружение маршрутов

`AttributeRouteLocator` реализует `RouteLocatorInterface`, `ClassListenerInterface` и `FinalizableListenerInterface`. В режиме разработки `RouteLocatorFactory` возвращает именно его.

Порядок работы:

1. `RouteLocatorFactory` берет `ConfigKey::ROUTES_FILE`, разрешает путь через `PathResolverInterface` и создает обычный `RouteLocator`.
2. `AttributeRouteLocator` сначала загружает явные маршруты из файла маршрутов.
3. Во время сканирования `class-finder` передает найденные классы в `AttributeRouteLocator::handle()`.
4. Локатор читает `#[Route]` через `Reflection::getDeepMetadata()`.
5. После сканирования `finalize()` сортирует найденные атрибуты по `priority` от большего к меньшему и добавляет их в коллекцию как `RouteRecord`.

Пример контроллера:

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

Атрибут описывает только маршрут. Как выполняются промежуточные обработчики и обработчик, определяют `componenta/router` и `componenta/middleware-factory`.

`methods` можно передать строкой (`'GET'`), строкой с разделителем `|` (`'GET|POST'`) или массивом (`['GET', 'POST']`). Если несколько атрибутных маршрутов могут совпасть с одним URI, раньше будет добавлен маршрут с большим `priority`.

## Файл маршрутов

Даже при обнаружении атрибутов `ConfigKey::ROUTES_FILE` остается точкой входа для маршрутов. Этот пакет задает значение по умолчанию `config/routes.php`. Используйте этот файл для ручной регистрации маршрутов, групп и общих настроек или переопределите ключ конфигурации, если приложение хранит маршруты в другом месте.

```php
use Componenta\Http\Router\Routes;

/** @var Routes $routes */

$api = $routes->group('api', '/api', middleware: ['api']);
$api->get('health', '/health', HealthController::class);
```

В разработке атрибутные маршруты добавляются к маршрутам из этого файла. В продакшене приложение должно читать скомпилированный кеш.

## Выбор локатора в разработке и продакшене

`RouteLocatorFactory` из `router-app` выбирает локатор по окружению:

| Условие | Что вернет фабрика |
|---|---|
| `APP_ENV=production` | Базовый `RouteLocatorFactory` из `componenta/router`. Он читает кеш, если кеш включен и файл существует. |
| Любое другое окружение | `AttributeRouteLocator`, который загружает файл маршрутов и дополняет его маршрутами из атрибутов. |

Базовый `RouteLocatorFactory` использует кеш только когда включен `ConfigKey::COMPILED_PIPELINE` и кеш-файл существует.

## Компиляция кеша маршрутов

`RouteCacheCompiler` подключается к сборке кеша через `CompileConfigKey::LISTENER_COMPILERS`. Он поддерживает только `AttributeRouteLocator`.

`compile()`:

- работает только в CLI;
- требует `ConfigKey::ROUTES_FILE`;
- берет путь кеша из `ConfigKey::ROUTES_CACHE_FILE`, если он задан;
- если путь кеша не задан, строит его из файла маршрутов: `routes.php` -> `routes.cache.php`;
- компилирует текущую коллекцию маршрутов через `RouteCacheGenerator`;
- возвращает `CompileResult::filesOnly()`, чтобы сборщик кеша записал PHP-файл.

Если маршруты не найдены, sidecar по-прежнему служит маркером скомпилированного пустого результата, но содержит ровно `return [];`. Стандартные ключи `version`, `staticRoutes` и `routeData` с пустыми значениями не записываются. Непустой cache содержит только секции, необходимые `CompiledRoutes`.

Пример конфигурации:

```php
use Componenta\Http\Router\ConfigKey;

return [
    ConfigKey::ROUTES_FILE => 'config/routes.php',
    ConfigKey::ROUTES_CACHE_FILE => 'var/cache/router/routes.cache.php',
    ConfigKey::COMPILED_PIPELINE => true,
];
```

## HTTP-перехватчики

`InterceptedRouteHandlerResolver` находится в `componenta/router-app`, потому что это интеграция роутера с HTTP-конвейером перехватчиков приложения. Базовый `componenta/router` выполняет обработчик напрямую через `RouteHandlerResolver` и не зависит от `componenta/interceptor`.

Провайдер по умолчанию добавляет резолвер в список резолверов `componenta/middleware-factory`:

```php
use Componenta\Http\Middleware\ConfigKey as MiddlewareConfigKey;
use Componenta\Http\Router\App\Resolver\InterceptedRouteHandlerResolver;

return [
    MiddlewareConfigKey::RESOLVERS => [
        InterceptedRouteHandlerResolver::class,
    ],
];
```

Фабрика `InterceptedRouteHandlerResolverFactory` берет:

| Зависимость | Откуда берется |
|---|---|
| `CallableExecutorInterface` | Из контейнера. |
| `PipelineInterface` | Из контейнера. Регистрируется пакетом `componenta/interceptor`. |
| `Responder` | Из контейнера, если сервис зарегистрирован. |

Если `Responder` не зарегистрирован, обработчик должен вернуть `ResponseInterface`, `MiddlewareInterface` или `RequestHandlerInterface`; остальные значения приведут к ошибке выполнения маршрута.

HTTP-конвейер перехватчиков создает `componenta/interceptor`. Его фабрика всегда добавляет `ParameterResolvingInterceptor`, а затем подключает сервисы перехватчиков из списка `Componenta\Interceptor\ConfigKey::HTTP_INTERCEPTORS`. Обычное HTTP-приложение добавляет в этот список `AttributeInterceptor::class`, чтобы обработчики маршрутов могли использовать `#[Intercept]`.

## Границы пакета

Используйте `componenta/router`, когда нужны:

- ручная регистрация маршрутов;
- сопоставление URI и HTTP-метода;
- генерация URL;
- группы маршрутов;
- PSR-15 промежуточные обработчики сопоставления и выполнения маршрута;
- загрузка уже скомпилированного кеша маршрутов.

Используйте `componenta/router-app`, когда нужны:

- обнаружение `#[Route]` в исходниках приложения;
- подключение компиляции маршрутов к сборке кеша приложения;
- добавление промежуточных обработчиков роутера в HTTP-приложение при старте;
- выполнение обработчика маршрута через HTTP-перехватчики.
