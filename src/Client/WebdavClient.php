<?php

namespace Bacart\WebdavClient\Client;

use Bacart\GuzzleClient\Exception\GuzzleClientException;
use Bacart\WebdavClient\Dto\WebdavDto;
use Bacart\WebdavClient\Exception\WebdavClientException;
use Psr\Cache\InvalidArgumentException;
use Wa72\HtmlPageDom\HtmlPageCrawler;

class WebdavClient extends AbstractWebdavClient
{
    /**
     * {@inheritdoc}
     */
    public function getSupportedMethods(): ?array
    {
        return $this->getFromCache(
            '',
            function (): ?array {
                return parent::getSupportedMethods();
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function listDirectory(
        string $path,
        int $page = WebdavClientInterface::ALL_PAGES,
        int $pageSize = WebdavClientInterface::DEFAULT_PAGE_SIZE,
        string $sortBy = WebdavClientInterface::XML_FIELD_DISPLAYNAME,
        int $sortOrder = WebdavClientInterface::SORT_ASC
    ): array {
        if (null === $this->cache) {
            return parent::listDirectory($path, $page, $pageSize, $sortBy, $sortOrder);
        }

        $args = \func_get_args();
        array_shift($args);
        $params = implode('|', $args);

        $path = trim($path, '/');
        $cacheItemKey = $this->getCacheItemKey(
            WebdavClientInterface::DIRECTORY_LIST_CACHE_ITEM_PREFIX.$path
        );

        try {
            $cacheItem = $this->cache->getItem($cacheItemKey);
        } catch (InvalidArgumentException $e) {
            if (null !== $this->logger) {
                $this->logger->error($e->getMessage(), [
                    'path' => $path,
                ]);
            }

            return parent::listDirectory($path, $page, $pageSize, $sortBy, $sortOrder);
        }

        $result = $cacheItem->isHit() ? $cacheItem->get() : [];

        if (empty($result[$params])) {
            $webdavDtos = parent::listDirectory(
                $path,
                $page,
                $pageSize,
                $sortBy,
                $sortOrder
            );

            if (null === $names = $this->saveWebdavDtos($webdavDtos, $path)) {
                throw new WebdavClientException(sprintf(
                    'Failed to save webdav DTOs for path "%s"',
                    $path
                ));
            }

            $result[$params] = $names;

            $cacheItem->set($result);
            $this->cache->save($cacheItem);
        } else {
            $this->logger->info('Webdav result was taken from cache', [
                'path' => $path,
            ]);
        }

        return array_map([$this, 'getPathInfo'], $result[$params]);
    }

    /**
     * {@inheritdoc}
     */
    public function getPathInfo(string $path): ?WebdavDto
    {
        return $this->getFromCache(
            $path,
            function (string $path): ?WebdavDto {
                return parent::getPathInfo($path);
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $path): bool
    {
        return $this->getFromCache(
            $path,
            function (string $path): bool {
                return parent::exists($path);
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function readFileAsString(string $path): ?string
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsString($path);
        } catch (GuzzleClientException $e) {
            if (null !== $this->logger) {
                $this->logger->error($e->getMessage(), [
                    'path' => $path,
                ]);
            }

            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function readFileAsJson(string $path): ?array
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsJson($path);
        } catch (GuzzleClientException $e) {
            if (null !== $this->logger) {
                $this->logger->error($e->getMessage(), [
                    'path' => $path,
                ]);
            }

            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function readFileAsHtmlPageCrawler(string $path): ?HtmlPageCrawler
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsHtmlPageCrawler($path);
        } catch (GuzzleClientException $e) {
            if (null !== $this->logger) {
                $this->logger->error($e->getMessage(), [
                    'path' => $path,
                ]);
            }

            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createDirectory(string $path): bool
    {
        return parent::createDirectory($path)
            && $this->deleteFromCache(\dirname($path));
    }

    /**
     * {@inheritdoc}
     */
    public function writeToFile(string $path, string $contents): bool
    {
        return parent::writeToFile($path, $contents)
            && $this->deleteFromCache($path);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): bool
    {
        return parent::delete($path)
            && $this->deleteFromCache($path)
            && $this->deleteFromCache(\dirname($path));
    }

    /**
     * @param WebdavDto[] $webdavDtos
     * @param string      $path
     *
     * @return null|string[]
     */
    protected function saveWebdavDtos(array $webdavDtos, string $path): ?array
    {
        $result = [];
        $savesCount = 0;

        /** @var WebdavDto $webdavDto */
        foreach ($webdavDtos as $webdavDto) {
            $filename = $path.'/'.$webdavDto->getName();
            $result[] = $filename;

            $cacheItemKey = $this->getCacheItemKey($filename);

            try {
                $cacheItem = $this->cache->getItem($cacheItemKey);
            } catch (InvalidArgumentException $e) {
                if (null !== $this->logger) {
                    $this->logger->error($e->getMessage(), [
                        'path' => $path,
                    ]);
                }

                return null;
            }

            if ($cacheItem->isHit()) {
                $cachedWebdavDto = $cacheItem->get();

                if ($cachedWebdavDto instanceof WebdavDto
                    && $cachedWebdavDto->getEtag() === $webdavDto->getEtag()) {
                    continue;
                }
            }

            $cacheItem->set($webdavDto);

            if ($this->cache->saveDeferred($cacheItem)) {
                ++$savesCount;
            }
        }

        if ($savesCount > 0) {
            $this->cache->commit();
        }

        return $result;
    }
}
