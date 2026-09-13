<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Tests;

use Componenta\Http\Router\Contract\SyntaxParserInterface;
use Componenta\Http\Router\Syntax\CompositeSyntax;
use PHPUnit\Framework\TestCase;

final class MountedSyntax implements SyntaxParserInterface
{
    private CompositeSyntax $base;
    public function __construct() { $this->base = new CompositeSyntax(); }
    public function canParse(string $pattern): bool { return $this->base->canParse($pattern); }
    public function parse(string $pattern): array { return $this->base->parse($pattern); }
    public function hasParameter(string $segment): bool { return $this->base->hasParameter($segment); }
    public function toRegex(string $pattern, array $tokens): string { return $this->base->toRegex($pattern, $tokens); }
    public function normalize(string $pattern): string { return $this->base->normalize($pattern); }
    public function buildPath(string $pattern, array $params, array $tokens, array $optional, string $routeName): string {
        return '/mounted' . $this->base->buildPath($pattern, $params, $tokens, $optional, $routeName);
    }
}

final class CustomGenerationSyntaxTest extends TestCase
{
    public function testBuildKeepsCustomGenerationOnTheOriginalSource(): void
    {
        $root = sys_get_temp_dir() . '/route_syntax_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            file_put_contents($root . '/routes.php', '<?php
                $routes = new \Componenta\Http\Router\Routes(syntax: new \Componenta\Http\Router\App\Tests\MountedSyntax());
                $routes->addRoute(\Componenta\Http\Router\RouteRecord::get("users", "/users/[id]", "handler"));');
            $container = static function (string $mode) use ($root): \Componenta\Config\ContainerValue {
                $composition = (new \Componenta\Config\ConfigFactory())->create(
                    new \Componenta\Config\Environment(['APP_ENV' => $mode]),
                    new \Componenta\App\ConfigProvider(),
                    new \Componenta\App\Console\ConfigProvider(),
                    new \Componenta\Http\Router\ConfigProvider(),
                    new \Componenta\Http\Router\App\ConfigProvider(),
                    static fn (): array => [
                        \Componenta\Http\Router\ConfigKey::ROUTES_FILE => 'routes.php',
                        \Componenta\Http\Router\ConfigKey::ROUTES_CACHE_FILE => 'cache.php',
                        \Componenta\Config\ConfigKey::DEPENDENCIES => [
                            \Componenta\Config\ConfigKey::SERVICES => [
                                \Componenta\Stdlib\PathResolverInterface::class => new \Componenta\Stdlib\PathResolver($root),
                                \Componenta\App\ConfigKey::DISCOVERY_SOURCE => new \Componenta\ClassFinder\ClassIterator([]),
                            ],
                        ],
                    ],
                );
                return (new \Componenta\DI\ContainerFactory())->create($composition->config, $composition->dependencies);
            };
            $before = $container('development')->get(\Componenta\Http\Router\Contract\RouteLocatorInterface::class)->getRoutes();
            self::assertSame('/mounted/users/42', $before->generate($before, 'users', ['id' => 42]));

            $command = $container('production')->get(\Componenta\App\Console\Command\BuildCommand::class);
            self::assertSame(0, (new \Symfony\Component\Console\Tester\CommandTester($command))->execute([]));

            $after = $container('production')->get(\Componenta\Http\Router\Contract\RouteLocatorInterface::class)->getRoutes();
            self::assertSame('/mounted/users/42', $after->generate($after, 'users', ['id' => 42]));
        } finally {
            foreach (glob($root . '/*') as $file) { unlink($file); }
            rmdir($root);
        }
    }
}
