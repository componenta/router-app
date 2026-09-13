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
                ]],
            ]);
        return (new ContainerFactory())->create($composition->config, $composition->dependencies);
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
            $routes = $this->container($mode)->get(RouteLocatorInterface::class)->getRoutes();
            self::assertSame(['stateful', 'attribute'], array_keys($routes->toArray()));
            $handler = $routes->getRoute('stateful')->handler->value;
            self::assertSame(1, $handler());
            self::assertSame(2, $handler());
        }
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
            $routes = $this->container($mode)->get(RouteLocatorInterface::class)->getRoutes(['prefix' => '/custom']);
            self::assertSame('/custom', $routes->getRoute('context')->path);
            self::assertTrue($routes->has('attribute'));
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
