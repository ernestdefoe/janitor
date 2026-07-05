<?php

namespace ErnestDefoe\Janitor\Api\Controller;

use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Publishes core + extension assets from the admin UI — the in-process
 * equivalent of `php flarum assets:publish`. Mirrors
 * Flarum\Foundation\Console\AssetsPublishCommand (Font Awesome webfonts,
 * the xslt polyfill when present, then every enabled extension's assets).
 */
class PublishAssetsController implements RequestHandlerInterface
{
    public function __construct(
        protected Container $container,
        protected Paths $paths,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $log = [];

        try {
            $target = $this->container->make('filesystem')->disk('flarum-assets');
            $local = new Filesystem();

            $log[] = 'Publishing core assets…';
            $pathPrefix = $this->paths->vendor.'/fortawesome/font-awesome/webfonts';
            if (is_dir($pathPrefix)) {
                foreach ($local->allFiles($pathPrefix) as $fullPath) {
                    $relPath = substr($fullPath, strlen($pathPrefix));
                    $target->put("fonts/$relPath", $local->get($fullPath));
                }
            }

            if (class_exists(\Flarum\Formatter\XsltPolyfill::class)) {
                $sourceDir = \Flarum\Formatter\XsltPolyfill::findSource();
                if ($sourceDir !== null) {
                    foreach ([
                        'xslt-polyfill.min.js' => 'xslt-polyfill/xslt-polyfill.min.js',
                        'dist/xslt-wasm.js' => 'xslt-polyfill/dist/xslt-wasm.js',
                    ] as $relSource => $relTarget) {
                        if ($local->exists("$sourceDir/$relSource")) {
                            $target->put($relTarget, $local->get("$sourceDir/$relSource"));
                        }
                    }
                }
            }

            $extensions = $this->container->make(ExtensionManager::class);
            foreach ($extensions->getEnabledExtensions() as $name => $extension) {
                if ($extension->hasAssets()) {
                    $log[] = 'Publishing for extension: '.$name;
                    $extension->copyAssetsTo($target);
                }
            }
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage(), 'log' => $log], 500);
        }

        return new JsonResponse(['ok' => true, 'log' => $log]);
    }
}
