<?php
declare(strict_types=1);
namespace Componenta\Http\Router\App\Tests;

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\App\Console\Command\BuildCommand;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey as DIConfigKey;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Http\Router\Attribute\Route;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\Tokenizer\ClassInfo;
use PHPUnit\Framework\TestCase;

#[Route('attribute', '/attribute/[id]')]
final class BuiltRouteAction { public function __invoke(): string { return 'ok'; } }

final class RouteBuildIntegrationTest extends TestCase
{
    private string $root;
    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/router_build_' . bin2hex(random_bytes(8));
        mkdir($this->root);
        file_put_contents($this->root . '/routes.php', '<?php $routes->addRoute(\\Componenta\\Http\\Router\\RouteRecord::get("file", "/file", "FileHandler"));');
    }
    protected function tearDown(): void {
        foreach (glob($this->root . '/*') as $file) { unlink($file); }
        rmdir($this->root);
    }
    private function container(string $mode, array $extra = []): \Componenta\Config\ContainerValue {
        $composition = (new ConfigFactory())->create(new Environment(['APP_ENV' => $mode]),
            new \Componenta\App\ConfigProvider(), new \Componenta\App\Console\ConfigProvider(),
            new \Componenta\Http\Router\ConfigProvider(), new \Componenta\Http\Router\App\ConfigProvider(),
            fn (): array => $extra + [
                ConfigKey::ROUTES_FILE => 'routes.php', ConfigKey::ROUTES_CACHE_FILE => 'optimized.php',
                DIConfigKey::DEPENDENCIES => [DIConfigKey::SERVICES => [
                    PathResolverInterface::class => new PathResolver($this->root),
                    \Componenta\App\ConfigKey::DISCOVERY_SOURCE => new ClassIterator([new ClassInfo(BuiltRouteAction::class)]),
                    \Componenta\ClassFinder\ClassIteratorInterface::class => new ClassIterator([new ClassInfo(BuiltRouteAction::class)]),
                ]],
            ]);
        return (new ContainerFactory())->create($composition->config, $composition->dependencies);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('largeRouteSets')]
    public function testLargeRouteSetsBuildAndMatchWithTheApplicationErrorHandler(int $count, int $prefixLength): void
    {
        file_put_contents($this->root . '/routes.php', '<?php
for ($index = 0; $index < ' . $count . '; ++$index) {
    $routes->addRoute(\\Componenta\\Http\\Router\\RouteRecord::get(
        "item-" . $index, "/" . str_repeat("a", ' . $prefixLength . ') . "/" . $index . "/[id]", "ItemHandler",
    ));
}');
        $last = $count - 1;
        $uri = '/' . str_repeat('a', $prefixLength) . '/' . $last . '/42';
        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });
        try {
            $source = $this->container('development')->get(RouteLocatorInterface::class)->getRoutes();
            self::assertSame('item-' . $last, $source->match($source, $uri, 'GET')->name);
            $command = new \Symfony\Component\Console\Tester\CommandTester($this->container('production')->get(BuildCommand::class));
            self::assertSame(0, $command->execute([]));
            $built = $this->container('production')->get(RouteLocatorInterface::class)->getRoutes();
            self::assertInstanceOf(CompiledRoutes::class, $built);
            self::assertSame('item-' . $last, $built->match($built, $uri, 'GET')->name);
            self::assertSame(['id' => 42], $built->match($built, $uri, 'GET')->parameters);
            self::assertSame($uri, $built->generate($built, 'item-' . $last, ['id' => 42]));
        } finally {
            restore_error_handler();
        }
    }

    public static function largeRouteSets(): iterable
    {
        yield 'unified expression exceeds PCRE limit' => [600, 120];
        yield 'fixed size chunks exceed PCRE limit' => [500, 1800];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRegexSections')]
    public function testInvalidCachedRegexFallsBackUnderTheApplicationErrorHandler(string $section): void
    {
        $this->container('production')->get(ApplicationBuildOrchestrator::class)->build();
        $file = $this->root . '/optimized.php';
        $cache = require $file;
        if ($section === 'regex') {
            $cache['regex']['GET'] = '#[#';
        } else {
            unset($cache['regex']);
            $cache['dynamicChunks']['GET'] = [['regex' => '#[#', 'routeMap' => ['r0' => 'attribute']]];
        }
        file_put_contents($file, '<?php return ' . var_export($cache, true) . ';');

        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });
        try {
            foreach (['development', 'production'] as $mode) {
                $routes = $this->container($mode)->get(RouteLocatorInterface::class)->getRoutes();
                self::assertSame(['file', 'attribute'], array_keys($routes->toArray()));
                self::assertSame(['id' => 42], $routes->match($routes, '/attribute/42', 'GET')->parameters);
            }
        } finally {
            restore_error_handler();
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('matchingRouteSets')]
    public function testPcreLimitsPreserveMatchesAndAllowedMethods(int $count, int $prefixLength): void
    {
        file_put_contents($this->root . '/routes.php', '<?php
for ($index = 0; $index < ' . $count . '; ++$index) {
    $routes->addRoute(\\Componenta\\Http\\Router\\RouteRecord::get(
        "item-" . $index, "/" . str_repeat("a", ' . $prefixLength . ') . "/" . $index . "/[id]", "Handler",
    ));
}');
        $last = $count - 1;
        $uri = '/' . str_repeat('a', $prefixLength) . '/' . $last . '/42';
        $this->container('production')->get(ApplicationBuildOrchestrator::class)->build();
        $source = $this->container('development')->get(RouteLocatorInterface::class)->getRoutes();
        $built = $this->container('production')->get(RouteLocatorInterface::class)->getRoutes();
        self::assertInstanceOf(CompiledRoutes::class, $built);

        $limit = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', '1');
            foreach ([$source, $built] as $routes) {
                self::assertSame('item-' . $last, $routes->match($routes, $uri, 'GET')->name);
                self::assertSame(['id' => 42], $routes->match($routes, $uri, 'GET')->parameters);
                try {
                    $routes->match($routes, $uri, 'POST');
                    self::fail('An existing GET route must produce method-not-allowed for POST.');
                } catch (\Componenta\Http\Router\Exception\MethodNotAllowedException $error) {
                    self::assertSame('GET', $error->allowHeader);
                }
            }
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
    }

    public static function matchingRouteSets(): iterable
    {
        yield 'unified matching' => [3, 10];
        yield 'chunk matching' => [500, 1800];
    }

    public static function invalidRegexSections(): iterable
    {
        yield 'unified expression' => ['regex'];
        yield 'chunk expression' => ['dynamicChunks'];
    }

    public function testBuiltMatchesKeepTheHandlersAndMiddlewaresOfTheirPublicRecords(): void
    {
        file_put_contents($this->root . '/routes.php', '<?php $routes->addRoute(
            \\Componenta\\Http\\Router\\RouteRecord::get("file", "/file", "FileHandler", ["Middleware"])
        );');
        $source = $this->container('development')->get(RouteLocatorInterface::class)->getRoutes();
        $command = new \Symfony\Component\Console\Tester\CommandTester($this->container('production')->get(BuildCommand::class));
        self::assertSame(0, $command->execute([]));
        $built = $this->container('production')->get(RouteLocatorInterface::class)->getRoutes();
        self::assertInstanceOf(CompiledRoutes::class, $built);

        foreach ([$source, $built] as $routes) {
            $match = $routes->match($routes, '/file', 'GET');
            $record = $routes->getRoute('file');
            self::assertSame($record->handler, $match->handler);
            self::assertSame($record->middlewares, $match->middlewares);
            self::assertSame($record, $match->route);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCompiledMetadata')]
    public function testInvalidDerivedMetadataPreservesDevelopmentBehavior(string $field, array $value, bool $global = false): void
    {
        $assertRoutes = static function ($routes): void {
            self::assertSame(['file', 'attribute'], array_keys($routes->toArray()));
            self::assertSame(['id' => 42], $routes->match($routes, '/attribute/42', 'GET')->parameters);
            self::assertSame('/attribute/23', $routes->generate($routes, 'attribute', ['id' => 23]));
            try {
                $routes->generate($routes, 'attribute', ['id' => 'abc']);
                self::fail('The id constraint must remain effective.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('id', $error->getMessage());
            }
        };
        $assertRoutes($this->container('production')->get(RouteLocatorInterface::class)->getRoutes());
        $command = new \Symfony\Component\Console\Tester\CommandTester($this->container('production')->get(BuildCommand::class));
        self::assertSame(0, $command->execute([]));
        $built = $this->container('production')->get(RouteLocatorInterface::class)->getRoutes();
        self::assertInstanceOf(CompiledRoutes::class, $built);
        $assertRoutes($built);

        $file = $this->root . '/optimized.php';
        $cache = require $file;
        if ($global) {
            $cache[$field] = $value;
        } else {
            $cache['routeData']['attribute'][$field] = $value;
        }
        file_put_contents($file, '<?php return ' . var_export($cache, true) . ';');
        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });
        try {
            foreach (['development', 'production'] as $mode) {
                $locator = $this->container($mode)->get(RouteLocatorInterface::class);
                $assertRoutes($locator->getRoutes());
                $assertRoutes($locator->getRoutes());
            }
        } finally {
            restore_error_handler();
        }
    }

    public static function invalidCompiledMetadata(): iterable
    {
        yield 'capture name' => ['paramNames', [['id']]];
        yield 'token pattern' => ['tokens', ['id' => []]];
        yield 'invalid token expression' => ['tokens', ['id' => '[']];
        yield 'invalid default token expression' => ['defaultTokens', ['id' => '['], true];
        yield 'default value' => ['defaults', ['extra' => []]];
    }

    public function testBuildAndFallbackKeepFileAndAttributeRoutes(): void {
        $builder = $this->container('production');
        self::assertSame(0, (new \Symfony\Component\Console\Tester\CommandTester($builder->get(BuildCommand::class)))->execute([]));
        self::assertFileExists($this->root . '/optimized.php');
        $built = $this->container('production')->get(RouteLocatorInterface::class)->getRoutes();
        self::assertInstanceOf(CompiledRoutes::class, $built);
        foreach (['development', 'production'] as $mode) {
            $routes = $this->container($mode)->get(RouteLocatorInterface::class)->getRoutes();
            self::assertSame(['file', 'attribute'], array_keys($routes->toArray()));
            self::assertSame(['id' => 12], $routes->match($routes, '/attribute/12', 'GET')->parameters);
            self::assertSame('/attribute/12', $routes->generate($routes, 'attribute', ['id' => 12]));
        }
        foreach ([
            null,
            '<?php return "invalid";',
            '<?php return [;',
            '<?php return \\MissingRouteCacheEnum::Default;',
            '<?php throw new \\RuntimeException("Unreadable cache");',
            '<?php trigger_error("Invalid cached value", E_USER_WARNING); return [];',
        ] as $content) {
            if ($content === null) { unlink($this->root . '/optimized.php'); }
            else { file_put_contents($this->root . '/optimized.php', $content); }
            $routes = $this->container('production')->get(RouteLocatorInterface::class)->getRoutes();
            self::assertSame(['file', 'attribute'], array_keys($routes->toArray()));
        }
    }
    public function testBuildDiscardsAnEarlierMapWhenAStatefulHandlerCannotBeExported(): void {
        $this->container('production')->get(ApplicationBuildOrchestrator::class)->build();
        file_put_contents($this->root . '/routes.php',
            '<?php $routes->addRoute(\\Componenta\\Http\\Router\\RouteRecord::get("stateful", "/stateful", static function () { static $count = 0; return ++$count; }));');
        $this->container('production')->get(ApplicationBuildOrchestrator::class)->build();
        foreach (['development', 'production'] as $mode) {
            $locator = $this->container($mode)->get(RouteLocatorInterface::class);
            $routes = $locator->getRoutes();
            self::assertSame(['stateful', 'attribute'], array_keys($routes->toArray()));
            self::assertSame(1, ($routes->getRoute('stateful')->handler->value)());
            self::assertSame(2, ($locator->getRoutes()->getRoute('stateful')->handler->value)());
            self::assertSame(1, ($locator->getRoutes(['unused' => true])->getRoute('stateful')->handler->value)());
            self::assertSame(1, ($locator->getRoutes(['unused' => true])->getRoute('stateful')->handler->value)());
            self::assertSame(3, ($locator->getRoutes()->getRoute('stateful')->handler->value)());
        }
    }

    public function testBuildPreservesMarkParametersAndFallsBackFromEarlierCaches(): void
    {
        file_put_contents($this->root . '/routes.php', <<<'PHP'
<?php
$routes->addRoute(\Componenta\Http\Router\RouteRecord::get('marked', '/items/{MARK}', 'ItemsHandler'));
PHP);
        $command = new \Symfony\Component\Console\Tester\CommandTester($this->container('production')->get(BuildCommand::class));
        self::assertSame(0, $command->execute([]));
        $built = $this->container('production')->get(RouteLocatorInterface::class)->getRoutes();
        self::assertInstanceOf(CompiledRoutes::class, $built);
        $source = $this->container('development')->get(RouteLocatorInterface::class)->getRoutes();
        foreach ([$source, $built] as $routes) {
            self::assertSame(['MARK' => 17], $routes->match($routes, '/items/17', 'GET')->parameters);
            self::assertSame('/items/23', $routes->generate($routes, 'marked', ['MARK' => 23]));
        }

        $file = $this->root . '/optimized.php';
        $cache = require $file;
        $cache['version'] = 4;
        $cache['regex'] = ['GET' => '#^(?:/items/(?P<MARK>[^/]+)(*MARK:r0)|/attribute/(?P<id>[^/]+)(*MARK:r1))$#J'];
        $cache['routeMap'] = ['GET' => ['r0' => 'marked', 'r1' => 'attribute']];
        unset($cache['dynamicChunks'], $cache['prefixIndex']);
        file_put_contents($file, '<?php return ' . var_export($cache, true) . ';');
        $routes = $this->container('production')->get(RouteLocatorInterface::class)->getRoutes();
        self::assertSame(['MARK' => 17], $routes->match($routes, '/items/17', 'GET')->parameters);
        self::assertSame(['id' => 42], $routes->match($routes, '/attribute/42', 'GET')->parameters);
    }

    public function testBuildPreservesNumericCaptureReferences(): void {
        file_put_contents($this->root . '/routes.php', <<<'PHP'
<?php
$routes->addRoute(\Componenta\Http\Router\RouteRecord::get(
    'pair', '/pair/[value]', 'PairHandler', tokens: ['value' => '(a)(b)\2'],
));
PHP);
        $this->container('production')->get(ApplicationBuildOrchestrator::class)->build();

        foreach (['development', 'production'] as $mode) {
            $routes = $this->container($mode)->get(RouteLocatorInterface::class)->getRoutes();
            self::assertSame(['value' => 'aba'], $routes->match($routes, '/pair/aba', 'GET')->parameters);
        }
    }

    public function testAnExplicitContextUsesTheOriginalRouteSource(): void {
        file_put_contents($this->root . '/routes.php',
            '<?php $routes->addRoute(\\Componenta\\Http\\Router\\RouteRecord::get("context", $prefix ?? "/default", "ContextHandler"));');
        $this->container('production')->get(ApplicationBuildOrchestrator::class)->build();
        foreach (['development', 'production'] as $mode) {
            $locator = $this->container($mode)->get(RouteLocatorInterface::class);
            foreach ([[], ['prefix' => '/custom'], ['prefix' => '/other'], []] as $context) {
                $routes = $locator->getRoutes($context);
                self::assertSame($context['prefix'] ?? '/default', $routes->getRoute('context')->path, $mode);
                self::assertTrue($routes->has('attribute'));
            }
        }
    }

    public function testBuildPreservesDuplicateParameterErrors(): void
    {
        file_put_contents($this->root . '/routes.php', <<<'PHP'
<?php
$routes->addRoute(\Componenta\Http\Router\RouteRecord::get('pair', '/pair/[id]/[id]', 'Handler'));
$routes->addRoute(\Componenta\Http\Router\RouteRecord::get('other', '/other/[id]', 'Handler'));
PHP);
        $this->container('production')->get(ApplicationBuildOrchestrator::class)->build();
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });
        try {
            foreach (['development', 'production'] as $mode) {
                $routes = $this->container($mode)->get(RouteLocatorInterface::class)->getRoutes();
                try {
                    $routes->match($routes, '/pair/1/2', 'GET');
                    self::fail($mode . ' accepted a duplicate parameter name.');
                } catch (\ErrorException $error) {
                    self::assertStringContainsString('two named subpatterns have the same name', $error->getMessage());
                }
            }
        } finally {
            restore_error_handler();
        }
    }

    public function testCommandHelpDoesNotCreateTheSourceOrWriteCache(): void {
        $calls = 0;
        $composition = (new ConfigFactory())->create(new Environment(['APP_ENV' => 'production']),
            new \Componenta\App\ConfigProvider(), new \Componenta\App\Console\ConfigProvider(),
            new \Componenta\Http\Router\ConfigProvider(), new \Componenta\Http\Router\App\ConfigProvider(),
            function () use (&$calls): array { return [
                ConfigKey::ROUTES_FILE => 'routes.php',
                DIConfigKey::DEPENDENCIES => [
                    DIConfigKey::SERVICES => [PathResolverInterface::class => new PathResolver($this->root)],
                    DIConfigKey::FACTORIES => [\Componenta\App\ConfigKey::DISCOVERY_SOURCE => static function () use (&$calls) {
                        ++$calls; return new ClassIterator([]);
                    }],
                ],
            ]; });
        $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
        $command = $container->get(BuildCommand::class);
        $app = new \Symfony\Component\Console\Application();
        $app->setAutoExit(false);
        $app->addCommand($command);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        self::assertSame(0, $app->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'app:build', '--help' => true]), $output));
        self::assertSame(0, $calls);
        self::assertFileDoesNotExist($this->root . '/routes.cache.php');
    }
}
