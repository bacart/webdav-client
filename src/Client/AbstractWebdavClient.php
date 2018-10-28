<?php

namespace Bacart\WebdavClient\Client;

use Bacart\Common\Exception\MissingPackageException;
use Bacart\GuzzleClient\Client\GuzzleClient;
use Bacart\GuzzleClient\Client\GuzzleClientInterface;
use Bacart\GuzzleClient\Exception\GuzzleClientException;
use Bacart\WebdavClient\Dto\WebdavDto;
use Bacart\WebdavClient\Dto\WebdavDtoInterface;
use Bacart\WebdavClient\Exception\WebdavClientException;
use GuzzleHttp\RequestOptions;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Wa72\HtmlPageDom\HtmlPage;

abstract class AbstractWebdavClient implements WebdavClientInterface
{
    /** @var GuzzleClient */
    protected $guzzleClient;

    /** @var LoggerInterface|null */
    protected $logger;

    /** @var CacheItemPoolInterface|null */
    protected $cache;

    /** @var string */
    protected $cachePrefix;

    /** @var string */
    protected $cacheTtl;

    /**
     * @param GuzzleClientInterface       $guzzleClient
     * @param LoggerInterface|null        $logger
     * @param CacheItemPoolInterface|null $cache
     * @param string|null                 $cachePrefix
     * @param string                      $cacheTtl
     */
    public function __construct(
        GuzzleClientInterface $guzzleClient,
        LoggerInterface $logger = null,
        CacheItemPoolInterface $cache = null,
        string $cachePrefix = null,
        string $cacheTtl = WebdavClientInterface::CACHE_TTL
    ) {
        $this->guzzleClient = $guzzleClient;
        $this->logger = $logger;
        $this->cache = $cache;

        $this->cachePrefix = WebdavClientInterface::CACHE_KEY_PREFIX.'|';
        $this->cachePrefix .= $cachePrefix ?: md5(
            static::class.'|'.$guzzleClient->getConfig(
                GuzzleClientInterface::BASE_URI
            )
        );

        $this->cacheTtl = $cacheTtl;
    }

    /**
     * @param string $path
     * @param string $href
     * @param string $name
     * @param string $type
     *
     * @return bool
     */
    protected function fileIsValid(
        string $path,
        string $href,
        string $name,
        string $type
    ): bool {
        return ltrim($href, '/') !== $path;
    }

    /**
     * @param string $path
     *
     * @throws WebdavClientException
     *
     * @return HtmlPage
     */
    protected function getHtmlPageCrawler(string $path): HtmlPage
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsHtmlPage(
                trim($path, '/'),
                [
                    RequestOptions::HEADERS => [
                        WebdavClientInterface::HEADER_DEPTH => 1,
                    ],
                ],
                GuzzleClientInterface::METHOD_PROPFIND
            );
        } catch (GuzzleClientException | MissingPackageException $e) {
            throw new WebdavClientException($e);
        }
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    protected function createSubdirectory(string $path): bool
    {
        try {
            $response = $this->guzzleClient->getGuzzleResponse(
                trim($path, '/'),
                [],
                GuzzleClientInterface::METHOD_MKCOL
            );

            return GuzzleClientInterface::HTTP_CREATED === $response->getStatusCode();
        } catch (GuzzleClientException $e) {
            $this->logException($e, $path);

            return false;
        }
    }

    /**
     * @param string $path
     *
     * @return string
     */
    protected function getCacheItemKey(string $path): string
    {
        return sprintf('%s|%s', $this->cachePrefix, md5($path));
    }

    /**
     * @param string   $path
     * @param callable $onMiss
     *
     * @return WebdavDtoInterface|null
     */
    protected function getFromCache(string $path, callable $onMiss): ?WebdavDtoInterface
    {
        $path = ltrim($path, '/');

        if (null === $this->cache) {
            return $onMiss($path);
        }

        $cacheItemKey = $this->getCacheItemKey($path);

        try {
            $cacheItem = $this->cache->getItem($cacheItemKey);
        } catch (InvalidArgumentException $e) {
            $this->logException($e, $path);

            return $onMiss($path);
        }

        if ($cacheItem->isHit()) {
            if (null !== $this->logger) {
                $this->logger->info('Webdav result is taken from cache', [
                    'path' => $path,
                ]);
            }

            return $cacheItem->get();
        }

        $result = $onMiss($path);

        try {
            $cacheItem
                ->set($result)
                ->expiresAfter(new \DateInterval($this->cacheTtl));
        } catch (\Exception $e) {
            $this->logException($e, $path);

            return $result;
        }

        $this->cache->save($cacheItem);

        return $result;
    }

    /**
     * @param WebdavDtoInterface $webdavDto
     * @param string             $path
     *
     * @return bool
     */
    protected function saveToCache(WebdavDtoInterface $webdavDto, string $path): bool
    {
        $path = ltrim($path, '/');

        if (null === $this->cache) {
            return false;
        }

        $cacheItemKey = $this->getCacheItemKey($path);

        try {
            $cacheItem = $this
                ->cache
                ->getItem($cacheItemKey)
                ->set($webdavDto)
                ->expiresAfter(new \DateInterval($this->cacheTtl));
        } catch (InvalidArgumentException | \Exception $e) {
            $this->logException($e, $path);

            return false;
        }

        return $this->cache->save($cacheItem);
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    protected function deleteFromCache(string $path): bool
    {
        if (null === $this->cache) {
            return true;
        }

        $path = ltrim($path, '/');
        $cacheItemKey = $this->getCacheItemKey($path);

        try {
            return $this->cache->deleteItem($cacheItemKey);
        } catch (InvalidArgumentException $e) {
            $this->logException($e, $path);
        }

        return false;
    }

    /**
     * @param string $name
     * @param string $type
     * @param string $etag
     * @param string $size
     * @param string $created
     * @param string $modified
     *
     * @return WebdavDtoInterface
     */
    protected function createWebdavDto(
        string $name,
        string $type,
        string $etag,
        string $size,
        string $created,
        string $modified
    ): WebdavDtoInterface {
        return new WebdavDto(
            $name,
            $type,
            $etag,
            (int) $size,
            new \DateTime($created),
            new \DateTime($modified)
        );
    }

    /**
     * @param \Throwable  $exception
     * @param string|null $path
     */
    protected function logException(
        \Throwable $exception,
        string $path = null
    ): void {
        if (null === $this->logger) {
            return;
        }

        $context = [];

        if (null !== $path) {
            $context['path'] = $path;
        }

        $this->logger->error($exception->getMessage(), $context);
    }
}
