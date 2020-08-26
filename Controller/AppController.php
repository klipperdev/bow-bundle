<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\BowBundle\Controller;

use Klipper\Bundle\BowBundle\Exception\RuntimeException;
use Klipper\Component\Content\Downloader\DownloaderInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class AppController
{
    private FirewallMap $firewallMap;

    private string $publicPath;

    private string $assetsPath;

    /**
     * @var string[]
     */
    private array $excludedFirewalls;

    public function __construct(
        FirewallMap $firewallMap,
        string $publicPath,
        string $assetsPath = '/assets',
        array $excludedFirewalls = []
    ) {
        $this->firewallMap = $firewallMap;
        $this->publicPath = $publicPath;
        $this->assetsPath = $assetsPath;
        $this->excludedFirewalls = $excludedFirewalls;
    }

    /**
     * @Route("/{path}",
     *     methods={"GET"},
     *     requirements={
     *         "path": ".+"
     *     },
     *     defaults={
     *         "path": "",
     *         "_priority": -1024
     *     }
     * )
     *
     * @throws
     */
    public function index(Request $request, DownloaderInterface $downloader, string $path): Response
    {
        $firewallConfig = $this->firewallMap->getFirewallConfig($request);

        if (null !== $firewallConfig && \in_array($firewallConfig->getName(), $this->excludedFirewalls, true)) {
            throw new NotFoundHttpException(Response::$statusTexts[Response::HTTP_NOT_FOUND]);
        }

        $remoteConfigPath = $this->publicPath.$this->assetsPath.'/remote-assets-config.json';
        $indexPath = $this->publicPath.$this->assetsPath.'/index.html';

        if (file_exists($remoteConfigPath)) {
            return $this->streamAsset($path, $remoteConfigPath);
        }

        if (!file_exists($indexPath)) {
            throw new RuntimeException('To launch the application, assets must be compiled or served with the dev server for the development');
        }

        if (!$this->isIndex($path) && file_exists($this->publicPath.$path)) {
            return $downloader->download($this->publicPath.$path);
        }

        return new Response($this->getIndexContent(file_get_contents($indexPath)));
    }

    private function getIndexContent(string $content): string
    {
        $crawler = new Crawler($content);
        $this->addRelativeUrlPrefix($crawler, ['href', 'src']);
        $content = '';

        foreach ($crawler as $domElement) {
            $content .= $domElement->ownerDocument->saveHTML($domElement);
        }

        return str_replace(
            'href="favicon.ico"',
            'href="'.$this->assetsPath.'/favicon.ico"',
            $content
        );
    }

    private function addRelativeUrlPrefix(Crawler $crawler, array $attributes): void
    {
        $selector = '['.implode('], [', $attributes).']';
        $nodes = $crawler->filter($selector);

        foreach ($nodes->getIterator() as $node) {
            foreach ($attributes as $attrName) {
                if (null !== $attr = $node->attributes->getNamedItem($attrName)) {
                    $v = $attr->nodeValue;

                    if (!empty($v) && false === strpos($v, '://') && 0 !== strpos($v, '/')) {
                        $attr->nodeValue = $this->assetsPath.'/'.$v;
                    }

                    break;
                }
            }
        }
    }

    /**
     * @throws
     */
    private function streamAsset(string $path, string $remoteConfigPath): Response
    {
        $data = json_decode(file_get_contents($remoteConfigPath), true, 512, JSON_THROW_ON_ERROR);
        $baseUrl = $data['assetBaseUrl'];
        $baseAsset = trim($this->assetsPath, '/').'/';
        $baseAssetLength = \strlen($baseAsset);
        $isIndexContent = false;
        $indexPath = $baseAsset.'index.html';

        if ($this->isIndex($path)) {
            $path = $indexPath;
            $isIndexContent = true;
        }

        if (0 === strpos($path, 'fonts/')) {
            $baseAsset = 'fonts/';
            $baseAssetLength = 0;
        }

        if (0 === strpos($path, $baseAsset)) {
            $client = HttpClient::create([
                'verify_host' => false,
                'verify_peer' => false,
            ]);
            $url = $baseUrl.'/'.substr($path, $baseAssetLength);
            $response = $client->request('GET', $url);
            $code = $response->getStatusCode();

            if ($code >= 200 && $code < 400) {
                $headers = $response->getHeaders();
                unset($headers['content-encoding'], $headers['transfer-encoding'], $headers['accept-ranges']);

                if ($isIndexContent) {
                    $res = new Response(
                        $this->getIndexContent($response->getContent()),
                        $code,
                        $headers
                    );
                } else {
                    $res = new StreamedResponse(null, $code, $headers);
                    $res->setCallback(static function () use ($client, $response): void {
                        foreach ($client->stream($response) as $chunk) {
                            echo $chunk->getContent();
                            ob_flush();
                            flush();
                        }
                    });
                }

                return $res;
            }

            throw new HttpException($code, $response->getContent());
        }

        if ($indexPath !== $path) {
            return $this->streamAsset($indexPath, $remoteConfigPath);
        }

        throw new NotFoundHttpException();
    }

    private function isIndex(string $path): bool
    {
        return \in_array($path, ['', '/'], true) || 0 === strpos($path, 'index.html');
    }
}
