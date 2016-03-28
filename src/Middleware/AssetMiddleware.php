<?php
namespace MiniAsset\Middleware;

use MiniAsset\AssetConfig;
use MiniAsset\Factory;
use Zend\Diactoros\Stream;
use Zend\Diactoros\Response;
use Zend\Diactoros\Response\TextResponse;

/**
 * A PSR7 middleware for serving assets from mini-asset.
 *
 * This provides a development grade middleware component that can
 * serve the assets that mini-asset could build. This component is *not*
 * recommended for production as it will be much slower than serving the
 * static files generated by the CLI tool.
 */
class AssetMiddleware
{
    private $config;
    private $outputDir;
    private $urlPrefix;

    /**
     * Constructor.
     *
     * @param \MiniAsset\AssetConfig $config The config instance for your application.
     * @param string $outputDir The directory development build caches should be stored in.
     *   Defaults to sys_get_temp_dir().
     * @param string $urlPrefix The URL prefix that assets are under. Defaults to /asset/.
     */
    public function __construct(AssetConfig $config, $outputDir = null, $urlPrefix = '/asset/')
    {
        $this->config = $config;
        $this->outputDir = $outputDir ?: sys_get_temp_dir();
        $this->urlPrefix = $urlPrefix;
    }

    /**
     * Apply the asset middleware.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Message\ResponseInterface $response The response.
     * @param callable $next The callable to invoke the next middleware layer.
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function __invoke($request, $response, $next)
    {
        $path = $request->getUri()->getPath();
        if (strpos($path, $this->urlPrefix) !== 0) {
            // Not an asset request.
            return $next($request, $response);
        }
        $factory = new Factory($this->config);
        $assets = $factory->assetCollection();

        $targetName = substr($path, strlen($this->urlPrefix));
        if (!$assets->contains($targetName)) {
            // Unknown build.
            return $next($request, $response);
        }
        $build = $assets->get($targetName);

        try {
            $compiler = $factory->compiler();
            $cacher = $factory->cacher($this->outputDir);
            if ($cacher->isFresh($build)) {
                $contents = $cacher->read($build);
            } else {
                $contents = $compiler->generate($build);
                $cacher->write($build, $contents);
            }
        } catch (Exception $e) {
            // Could not build the asset.
            return new TextResponse($e->getMessage(), 400);
        }
        return $this->respond($contents, $build->ext());
    }

    private function respond($contents, $ext)
    {
        // Deliver built asset.
        $body = new Stream('php://temp', 'wb+');
        $body->write($contents);
        $body->rewind();

        $headers = [
            'Content-Type' => $this->mapType($ext)
        ];
        return new Response($body, 200, $headers);
    }

    private function mapType($ext)
    {
        $types = [
            'css' => 'application/css',
            'js' => 'application/javascript'
        ];
        return isset($types[$ext]) ? $types[$ext] : 'application/octet-stream';
    }
}
