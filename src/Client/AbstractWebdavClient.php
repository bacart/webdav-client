<?php

namespace Bacart\WebdavClient\Client;

use Bacart\Common\Exception\MissingPackageException;
use Bacart\Common\Util\ClassUtils;
use Bacart\GuzzleClient\Client\GuzzleClient;
use Bacart\GuzzleClient\Client\GuzzleClientInterface;
use Bacart\GuzzleClient\Exception\GuzzleClientException;
use Bacart\WebdavClient\Dto\WebdavDto;
use Bacart\WebdavClient\Exception\WebdavClientException;
use GuzzleHttp\RequestOptions;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Wa72\HtmlPageDom\HtmlPage;
use function GuzzleHttp\Psr7\mimetype_from_filename;

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
        $path = trim($path, '/');

        $nodes = $this
            ->getHtmlPageCrawler($path)
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

        if ($page > WebdavClientInterface::ALL_PAGES) {
            $result = array_chunk($result, $pageSize)[$page] ?? [];
        }

        return array_map([$this, 'createWebdavDto'], $result);
    }

    /**
     * {@inheritdoc}
     */
    public function getPathInfo(string $path): ?WebdavDto
    {
        try {
            $node = $this
                ->getHtmlPageCrawler($path)
                ->filter(WebdavClientInterface::XML_FILTER);
        } catch (WebdavClientException $e) {
            if (null !== $this->logger) {
                $this->logger->error($e->getMessage(), [
                    'path' => $path,
                ]);
            }

            return null;
        }

        if (0 === $node->count()) {
            return null;
        }

        try {
            return $this->createWebdavDto($node->first());
        } catch (WebdavClientException $e) {
            return null;
        }
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
     * @throws WebdavClientException
     *
     * @return WebdavDto
     */
    protected function createWebdavDto(Crawler $crawler): WebdavDto
    {
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

        return new WebdavDto(
            $name,
            $type,
            $etag,
            (int) $size,
            new \DateTime($created),
            new \DateTime($modified)
        );
    }
}
