<?php

namespace ErnestDefoe\Janitor\Api\Controller;

use Flarum\Database\Migrator;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\Application;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs outstanding core + extension migrations from the admin UI — the
 * in-process equivalent of `php flarum migrate`, for hosts where the admin
 * has no shell (shared hosting, managed containers). Mirrors
 * Flarum\Database\Console\MigrateCommand::upgrade() step for step and
 * returns the migrator's own output so the admin sees exactly what ran.
 */
class RunMigrationsController implements RequestHandlerInterface
{
    public function __construct(
        protected Container $container,
        protected Paths $paths,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $output = new BufferedOutput();

        try {
            $this->container->bind(Builder::class, function ($container) {
                return $container->make(ConnectionInterface::class)->getSchemaBuilder();
            });

            $migrator = $this->container->make(Migrator::class);
            $migrator->setOutput($output);
            $migrator->run($this->paths->vendor.'/flarum/core/migrations');

            $extensions = $this->container->make(ExtensionManager::class);
            $extensions->getMigrator()->setOutput($output);
            $extensions->syncExtensionOrder();

            foreach ($extensions->getEnabledExtensions() as $name => $extension) {
                if ($extension->hasMigrations()) {
                    $output->writeln('Migrating extension: '.$name);
                    $extensions->migrate($extension);
                }
            }

            $this->container->make(SettingsRepositoryInterface::class)->set('version', Application::VERSION);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => $e->getMessage(),
                'log' => $this->lines($output),
            ], 500);
        }

        return new JsonResponse(['ok' => true, 'log' => $this->lines($output)]);
    }

    /** @return string[] */
    protected function lines(BufferedOutput $output): array
    {
        return array_values(array_filter(array_map(
            fn (string $line) => trim(strip_tags($line)),
            explode("\n", $output->fetch())
        )));
    }
}
