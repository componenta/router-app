<?php

declare(strict_types=1);

namespace Componenta\Http\Router\App\Console;

use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'router:list',
    description: 'List registered HTTP routes',
)]
final class RouterListCommand extends Command
{
    public function __construct(
        private readonly RouteLocatorInterface $routes,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $routes = $this->routes->getRoutes();
        $rows = [];

        foreach ($routes as $route) {
            $rows[] = [
                implode('|', $route->methods),
                $route->path,
                $route->name,
                $route->group ?? '',
                $this->formatHandler($route->handler->value),
            ];
        }

        $io->table(['Method', 'Path', 'Name', 'Group', 'Handler'], $rows);
        $io->text(sprintf('%d route(s)', count($routes)));

        return Command::SUCCESS;
    }

    private function formatHandler(mixed $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;

            return sprintf(
                '%s::%s',
                is_object($class) ? $class::class : (string) $class,
                (string) $method,
            );
        }

        if (is_object($handler)) {
            return $handler::class;
        }

        return get_debug_type($handler);
    }
}
