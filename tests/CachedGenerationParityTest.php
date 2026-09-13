<?php
declare(strict_types=1);
namespace Componenta\Http\Router\App\Tests;

use Componenta\App\Console\Command\BuildCommand;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey as DependencyKey;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CachedGenerationParityTest extends TestCase
{
    public static function paths(): array
    {
        return [
            'curly suffix' => ['/images/{id}_thumb.jpg', '/images/42_thumb.jpg'],
            'square suffix' => ['/images/[id]_thumb.jpg', '/images/42_thumb.jpg'],
            'angle suffix' => ['/images/<id>_thumb.jpg', '/images/42_thumb.jpg'],
            'literal colon' => ['/images/{id}/:preview', '/images/42/:preview'],
        ];
    }

    #[DataProvider('paths')]
    public function testFreshBuildPreservesGeneration(string $path, string $expected): void
    {
        $root = sys_get_temp_dir() . '/componenta_route_recheck_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $literal = \Componenta\VarExport\VarExport::withDefaults()->export($path);
            file_put_contents($root . '/routes.php',
                '<?php $routes->addRoute(\Componenta\Http\Router\RouteRecord::get("image", ' . $literal . ', "ImageHandler"));');
            $container = static function (string $mode) use ($root): ContainerValue {
                $composition = (new ConfigFactory())->create(new Environment(['APP_ENV' => $mode]),
                    new \Componenta\App\ConfigProvider(),
                    new \Componenta\App\Console\ConfigProvider(),
                    new \Componenta\Http\Router\ConfigProvider(),
                    new \Componenta\Http\Router\App\ConfigProvider(),
                    static fn (): array => [
                        ConfigKey::ROUTES_FILE => 'routes.php',
                        ConfigKey::ROUTES_CACHE_FILE => 'routes.cache.php',
                        DependencyKey::DEPENDENCIES => [DependencyKey::SERVICES => [
                            PathResolverInterface::class => new PathResolver($root),
                            \Componenta\App\ConfigKey::DISCOVERY_SOURCE => new ClassIterator([]),
                        ]],
                    ],
                );
                return (new ContainerFactory())->create($composition->config, $composition->dependencies);
            };

            foreach (['development', 'production'] as $mode) {
                $routes = $container($mode)->get(RouteLocatorInterface::class)->getRoutes();
                self::assertSame($expected, $routes->generate($routes, 'image', ['id' => 42]));
                self::assertSame(['id' => 42], $routes->match($routes, $expected, 'GET')->parameters);
            }
            self::assertSame(0, (new CommandTester($container('production')->get(BuildCommand::class)))->execute([]));
            $optimized = $container('production')->get(RouteLocatorInterface::class)->getRoutes();
            self::assertSame(['id' => 42], $optimized->match($optimized, $expected, 'GET')->parameters);
            self::assertSame($expected, $optimized->generate($optimized, 'image', ['id' => 42]));
        } finally {
            foreach (glob($root . '/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }
}
