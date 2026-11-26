# Componenta Router App

Интеграционный пакет для `componenta/router` в Componenta-приложении. Пакет добавляет обнаружение маршрутов по `#[Route]`, сборку карты через `app:build`, загрузчик HTTP-маршрутизации и резолвер обработчиков маршрута через HTTP-перехватчики.

Если коду нужно только сопоставить путь с маршрутом, сгенерировать URL или выполнить найденный маршрут без HTTP-перехватчиков, он должен зависеть от `componenta/router`. `componenta/router-app` связывает роутер с обнаружением классов и конвейером перехватчиков приложения.

## Зависимости

Версия PHP:

| Требование | Версия |
|---|---|
| PHP | `^8.5.13` |

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
| `RouteLocatorInterface` | `RouteLocatorFactory` из `router-app`, которая выбирает исходный локатор или карту в production. |
| `RouteBuilder` | Фабрика билдера с исходным локатором и настроенным компилятором маршрутов. |
| `AppConfigKey::BUILDERS` | Добавляет `RouteBuilder` к обычной команде `app:build`. |
| `InterceptedRouteHandlerResolver` | Фабрика резолвера обработчиков маршрута через HTTP-перехватчики. |
| `AppConfigKey::BOOTLOADERS` | Добавляет `RoutingBootloader` для HTTP-приложений. |
| `AttributeRouteLocator` | Получает общий исходный discovery через DI и читает его при запросе маршрутов. |
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

Исходный локатор добавляет атрибутные маршруты к маршрутам из этого файла. Билдер получает оба набора через тот же локатор.

## Runtime и сборка

`RouteBuilder` реализует `ApplicationBuilderInterface`. Команда `app:build` получает
его через существующий контейнер. Создание команды и показ справки не создают
билдер и не запускают discovery ради сборки. Runtime не запускает сборку.

Билдер читает исходные маршруты из общего `app.discovery.source` и публикует карту
через `PhpMapFile` App с поколением `app.build.generation`. Карта фиксирует значения на момент сборки. После изменения
маршрутов, конфигурации или внешних входных данных выполните `app:build` заново.
Development и production сравниваются на одинаковых входных данных с актуальной
картой.

В development используется `AttributeRouteLocator`. В production при включённом
`COMPILED_PIPELINE` используется карта только при совпадении её поколения с
`app.discovery.generation`. Отсутствующая, несовместимая, невалидная или устаревшая
карта приводит к исходному локатору, включая атрибутные маршруты. При
`APP_DISCOVERY_SOURCE` оптимизированная карта не используется. Явный контекст
`getRoutes($context)` также передаётся источнику.

Пользовательские атрибуты, компиляторы и синтаксисы, объекты с состоянием и значения,
которые нельзя экспортировать с сохранением поведения, остаются на исходном пути.
Билдер заменяет предыдущую карту маркером fallback, чтобы старые маршруты не
оставались активными. Исключения исходного локатора передаются вызывающему коду.

При миграции удалите регистрации `RouteCacheCompiler` и старых route autowire
contributors. `RouteBuilder` уже регистрируется провайдером пакета. Пересоберите
карту командой `app:build`.

Пример конфигурации:

```php
use Componenta\Http\Router\ConfigKey;

return [
    ConfigKey::ROUTES_FILE => 'config/routes.php',
    ConfigKey::ROUTES_CACHE_FILE => 'var/cache/router/routes.cache.php',
    ConfigKey::COMPILED_PIPELINE => true,
];
```

Имя кеш-файла произвольно. Если оно не задано, перед расширением исходного файла
добавляется `.cache`. Кеш и источник должны находиться по разным путям.

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
- подготовка карты маршрутов командой `app:build`;
- добавление промежуточных обработчиков роутера в HTTP-приложение при старте;
- выполнение обработчика маршрута через HTTP-перехватчики.

Провайдер базового роутера регистрирует единый идентификатор резолвера обработчика. Провайдер router-app подключается после базового, как и для локатора маршрутов: он заменяет алиас на резолвер с интерцепторами, а не добавляет конкурирующий резолвер в цепочку middleware.

Повторная сборка заново загружает файл маршрутов в рамках общего снимка обнаруженных классов и не изменяет ранее созданную runtime-коллекцию. Допустимость подготовки определяет генератор кеша роутера: неподдерживаемые настройки компилятора или синтаксиса сохраняют исходный путь. Ошибки определения маршрутов не скрываются и прерывают сборку.
