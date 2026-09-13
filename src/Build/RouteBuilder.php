<?php
declare(strict_types=1);
namespace Componenta\Http\Router\App\Build;
use Componenta\App\Build\ApplicationBuilderInterface;
use Componenta\Http\Router\App\Locator\AttributeRouteLocator;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Routes;
use Componenta\Http\Router\Syntax\CompositeSyntax;
use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\EnumExporter;
use Componenta\VarExport\ScalarExporter;
use Componenta\VarExport\VarExport;
use Componenta\VarExport\Exception\ExceptionInterface as ExportException;
use ErrorException;
use RuntimeException;

final readonly class RouteBuilder implements ApplicationBuilderInterface
{
    public function __construct(private AttributeRouteLocator $source, private string $file, private RouteCacheGenerator $generator) {}

    public function build(): void
    {
        $routes = $this->source->getRoutes();
        $expression = 'null';
        $compiler = $this->generator->compiler;
        if ($this->source->canOptimize() && $routes instanceof Routes
            && $routes->compiler == $compiler && $compiler instanceof Compiler
            && ($routes->syntax === null || ($routes->syntax instanceof CompositeSyntax && $routes->syntax == new CompositeSyntax()))
            && $compiler->syntax instanceof CompositeSyntax && $compiler->syntax == new CompositeSyntax()) {
            $data = $this->generator->compile($routes);
            try {
                // Stateful callables and arbitrary objects keep the complete original route source.
                $expression = (new VarExport(new ScalarExporter(), new ArrayExporter(), new EnumExporter()))->export($data);
            } catch (ExportException) {
                $expression = 'null';
            }
        }
        $this->write($this->file, "<?php\ndeclare(strict_types=1);\nreturn " . $expression . ";\n");
    }

    private function write(string $file, string $content): void
    {
        $directory = dirname($file);
        $temporary = null;
        $stream = null;
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
                throw new RuntimeException('Cannot create routes cache directory "' . $directory . '".');
            }
            $temporary = $directory . '/.' . basename($file) . '.' . bin2hex(random_bytes(12)) . '.tmp';
            $stream = fopen($temporary, 'xb');
            if ($stream === false || fwrite($stream, $content) !== strlen($content) || !fflush($stream)) {
                throw new RuntimeException('Cannot write complete routes cache "' . $file . '".');
            }
            fclose($stream);
            $stream = null;
            if (!rename($temporary, $file)) {
                throw new RuntimeException('Cannot publish routes cache "' . $file . '".');
            }
            $temporary = null;
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        } finally {
            try {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if ($temporary !== null && is_file($temporary)) {
                    unlink($temporary);
                }
            } finally {
                restore_error_handler();
            }
        }
    }
}
