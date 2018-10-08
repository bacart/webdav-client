<?php

namespace Bacart\WebdavClient\Client;

use Bacart\Common\Util\ClassUtils;
use Bacart\GuzzleClient\Client\GuzzleClientInterface;
use Bacart\GuzzleClient\Exception\GuzzleClientException;
use Bacart\WebdavClient\Dto\WebdavDto;
use Bacart\WebdavClient\Exception\WebdavClientException;
use GuzzleHttp\RequestOptions;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Wa72\HtmlPageDom\HtmlPageCrawler;
use function GuzzleHttp\Psr7\mimetype_from_filename;

abstract class AbstractWebdavClient implements WebdavClientInterface
{
    /** @var GuzzleClientInterface */
    protected $guzzleClient;

    /** @var LoggerInterface|null */
    protected $logger;

    /** @var CacheItemPoolInterface|null */
    protected $cache;

    /** @var string */
    protected $cachePrefix;

    /** @var string[] */
    protected $xmlFieldTypes;

    /**
     * @param GuzzleClientInterface       $guzzleClient
     * @param LoggerInterface|null        $logger
     * @param CacheItemPoolInterface|null $cache
     * @param string|null                 $cachePrefix
     */
    public function __construct(
        GuzzleClientInterface $guzzleClient,
        LoggerInterface $logger = null,
        CacheItemPoolInterface $cache = null,
        string $cachePrefix = null
    ) {
        $this->guzzleClient = $guzzleClient;
        $this->logger = $logger;
        $this->cache = $cache;

        $this->cachePrefix = $cachePrefix ?: md5(
            static::class.'|'.$guzzleClient->getConfig(
                GuzzleClientInterface::BASE_URI
            )
        );

        $this->xmlFieldTypes = ClassUtils::getClassConstants(
            static::class,
            'XML_FIELD_'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedMethods(): ?array
    {
        try {
            $response = $this->guzzleClient->getGuzzleResponse(
                '',
                [],
                GuzzleClientInterface::METHOD_OPTIONS
            );
        } catch (GuzzleClientException $e) {
            if (null !== $this->logger) {
                $this->logger->error($e->getMessage());
            }

            return null;
        }

        $allow = $response->hasHeader(WebdavClientInterface::HEADER_ALLOW)
            ? $response->getHeader(WebdavClientInterface::HEADER_ALLOW)[0] ?? ''
            : '';

        return array_map(
            'trim',
            explode(',', $allow)
        );
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebdavClientException
     */
    public function listDirectory(
        string $path,
        int $page = WebdavClientInterface::ALL_PAGES,
        int $pageSize = WebdavClientInterface::DEFAULT_PAGE_SIZE,
        string $sortBy = WebdavClientInterface::XML_FIELD_DISPLAYNAME,
        int $sortOrder = WebdavClientInterface::SORT_ASC
    ): array {
        return array_map(
            [$this, 'createWebdavDto'],
            $this->listDirectoryHelper($path, $page, $pageSize, $sortBy, $sortOrder)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getPathInfo(string $path): ?WebdavDto
    {
        try {
            $node = $this
                ->getCrawler($path)
                ->filter(WebdavClientInterface::XML_FILTER);
        } catch (WebdavClientException $e) {
            if (null !== $this->logger) {
                $this->logger->error($e->getMessage(), [
                    'path' => $path,
                ]);
            }

            return null;
        }

        return $node->count()
            ? $this->createWebdavDto($node->first())
            : null;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $path): bool
    {
        return null !== $this->getPathInfo($path);
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebdavClientException
     */
    public function createDirectory(string $path): bool
    {
        if ($this->exists($path)) {
            return true;
        }

        $directory = trim($path, '/');
        $directoryParts = explode('/', $directory);
        $subdirectory = '';

        foreach ($directoryParts as $directoryPart) {
            $subdirectory = ltrim(
                $subdirectory.'/'.$directoryPart,
                '/'
            );

            if ($this->exists($subdirectory)) {
                continue;
            }

            if (!$this->createSubdirectory($subdirectory)) {
                throw new WebdavClientException(sprintf(
                    'Directory "%s" was not created',
                    $subdirectory
                ));
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebdavClientException
     */
    public function writeToFile(string $path, string $contents): bool
    {
        if (!$this->createDirectory(\dirname($path))) {
            return false;
        }

        try {
            $response = $this->guzzleClient->getGuzzleResponse(
                trim($path, '/'),
                [
                    RequestOptions::BODY => $contents,
                ],
                GuzzleClientInterface::METHOD_PUT
            );

            return GuzzleClientInterface::HTTP_CREATED === $response->getStatusCode();
        } catch (GuzzleClientException $e) {
            throw new WebdavClientException($e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebdavClientException
     */
    public function delete(string $path): bool
    {
        if (!$this->exists($path)) {
            return false;
        }

        $deletedStatuses = [
            GuzzleClientInterface::HTTP_OK,
            GuzzleClientInterface::HTTP_NO_CONTENT,
        ];

        try {
            $response = $this->guzzleClient->getGuzzleResponse(
                trim($path, '/'),
                [],
                GuzzleClientInterface::METHOD_DELETE
            );

            return \in_array(
                $response->getStatusCode(),
                $deletedStatuses,
                true
            );
        } catch (GuzzleClientException $e) {
            throw new WebdavClientException($e);
        }
    }

    /**
     * @param string $path
     *
     * @throws WebdavClientException
     *
     * @return HtmlPageCrawler
     */
    protected function getCrawler(string $path): HtmlPageCrawler
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsHtmlPageCrawler(
                trim($path, '/'),
                [
                    RequestOptions::HEADERS => [
                        WebdavClientInterface::HEADER_DEPTH => 1,
                    ],
                ],
                GuzzleClientInterface::METHOD_PROPFIND
            );
        } catch (GuzzleClientException $e) {
            throw new WebdavClientException($e);
        }
    }

    /**
     * @param Crawler $crawler
     * @param string  $xmlFieldType
     *
     * @throws WebdavClientException
     *
     * @return string
     */
    protected function getCrawlerXmlFieldValue(
        Crawler $crawler,
        string $xmlFieldType
    ): string {
        if (!\in_array($xmlFieldType, $this->xmlFieldTypes, true)) {
            throw new WebdavClientException(sprintf(
                'Invalid XML field type "%s"',
                $xmlFieldType
            ));
        }

        try {
            $field = $crawler->filter($xmlFieldType);

            $result = $field->count()
                ? $field->text()
                : '';
        } catch (\InvalidArgumentException $e) {
            throw new WebdavClientException($e);
        }

        if (WebdavClientInterface::XML_FIELD_GETCONTENTTYPE !== $xmlFieldType) {
            return $result;
        }

        $name = $this->getCrawlerXmlFieldValue(
            $crawler,
            WebdavClientInterface::XML_FIELD_DISPLAYNAME
        );

        $type = mimetype_from_filename($name) ?: $result;

        return $type ?: WebdavClientInterface::TYPE_DIRECTORY;
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebdavClientException
     *
     * @return Crawler[]
     */
    protected function listDirectoryHelper(
        string $path,
        int $page = WebdavClientInterface::ALL_PAGES,
        int $pageSize = WebdavClientInterface::DEFAULT_PAGE_SIZE,
        string $sortBy = WebdavClientInterface::XML_FIELD_CREATIONDATE,
        int $sortOrder = WebdavClientInterface::SORT_DESC
    ): array {
        $path = trim($path, '/');

        $nodes = $this
            ->getCrawler($path)
            ->filter(WebdavClientInterface::XML_FILTER);

        $items = [
            WebdavClientInterface::TYPE_DIRECTORY => [],
            WebdavClientInterface::TYPE_FILE      => [],
        ];

        foreach ($nodes as $node) {
            $crawler = new Crawler($node);

            $href = $this->getCrawlerXmlFieldValue(
                $crawler,
                WebdavClientInterface::XML_FIELD_HREF
            );

            if (trim($href, '/') === $path) {
                continue;
            }

            $name = $this->getCrawlerXmlFieldValue(
                $crawler,
                WebdavClientInterface::XML_FIELD_DISPLAYNAME
            );

            $type = $this->getCrawlerXmlFieldValue(
                $crawler,
                WebdavClientInterface::XML_FIELD_GETCONTENTTYPE
            );

            if (WebdavClientInterface::TYPE_DIRECTORY !== $type) {
                $type = WebdavClientInterface::TYPE_FILE;
            }

            $key = $this->getCrawlerXmlFieldValue($crawler, $sortBy).'|'.$name;
            $items[$type][$key] = $crawler;
        }

        foreach (array_keys($items) as $itemKey) {
            switch ($sortOrder) {
                case WebdavClientInterface::SORT_ASC:
                    ksort($items[$itemKey]);

                    break;
                case WebdavClientInterface::SORT_DESC:
                    krsort($items[$itemKey]);

                    break;
                default:
                    throw new WebdavClientException(sprintf(
                        'Invalid sort order "%d"',
                        $sortOrder
                    ));

                    break;
            }

            $items[$itemKey] = array_values($items[$itemKey]);
        }

        $result = array_merge(
            $items[WebdavClientInterface::TYPE_DIRECTORY],
            $items[WebdavClientInterface::TYPE_FILE]
        );

        return $page > WebdavClientInterface::ALL_PAGES
            ? array_chunk($result, $pageSize)[$page] ?? []
            : $result;
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
            if (null !== $this->logger) {
                $this->logger->error($e->getMessage(), [
                    'path' => $path,
                ]);
            }

            return false;
        }
    }

    /**
     * @param Crawler $crawler
     *
     * @return WebdavDto
     */
    protected function createWebdavDto(Crawler $crawler): WebdavDto
    {
        try {
            $name = $this->getCrawlerXmlFieldValue(
                $crawler,
                WebdavClientInterface::XML_FIELD_DISPLAYNAME
            );

            $type = $this->getCrawlerXmlFieldValue(
                $crawler,
                WebdavClientInterface::XML_FIELD_GETCONTENTTYPE
            );

            $created = $this->getCrawlerXmlFieldValue(
                $crawler,
                WebdavClientInterface::XML_FIELD_CREATIONDATE
            );

            $modified = $this->getCrawlerXmlFieldValue(
                $crawler,
                WebdavClientInterface::XML_FIELD_GETLASTMODIFIED
            );

            $etag = $this->getCrawlerXmlFieldValue(
                $crawler,
                WebdavClientInterface::XML_FIELD_GETETAG
            );

            $size = $this->getCrawlerXmlFieldValue(
                $crawler,
                WebdavClientInterface::XML_FIELD_GETCONTENTLENGTH
            );
        } catch (WebdavClientException $e) {
            if (null !== $this->logger) {
                $this->logger->error($e->getMessage());
            }

            return null;
        }

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
     * @param string $path
     *
     * @return string
     */
    protected function getCacheItemKey(string $path): string
    {
        return md5($this->cachePrefix.'|'.trim($path, '/'));
    }

    /**
     * @param string   $path
     * @param callable $onMiss
     *
     * @return mixed
     */
    protected function getFromCache(string $path, callable $onMiss)
    {
        $path = trim($path, '/');

        if (null === $this->cache) {
            return $onMiss($path);
        }

        $cacheItemKey = $this->getCacheItemKey($path);

        try {
            $cacheItem = $this->cache->getItem($cacheItemKey);
        } catch (InvalidArgumentException $e) {
            if (null !== $this->logger) {
                $this->logger->error($e->getMessage(), [
                    'path' => $path,
                ]);
            }

            return $onMiss($path);
        }

        if ($cacheItem->isHit()) {
            if (null !== $this->logger) {
                $this->logger->info('Webdav result was taken from cache', [
                    'path' => $path,
                ]);
            }

            return $cacheItem->get();
        }

        $result = $onMiss($path);
        $cacheItem->set($result);
        $this->cache->save($cacheItem);

        return $result;
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

        $path = trim($path, '/');

        $cacheItemKeys = [
            $this->getCacheItemKey($path),
            $this->getCacheItemKey(
                WebdavClientInterface::DIRECTORY_LIST_CACHE_ITEM_PREFIX.$path
            ),
        ];

        try {
            return $this->cache->deleteItems($cacheItemKeys);
        } catch (InvalidArgumentException $e) {
            if (null !== $this->logger) {
                $this->logger->error($e->getMessage(), [
                    'path' => $path,
                ]);
            }
        }

        return false;
    }
}
