<?php

namespace Bacart\WebdavClient\Client;

use Bacart\WebdavClient\Dto\WebdavDto;
use Bacart\WebdavClient\Dto\WebdavDtoInterface;
use Bacart\WebdavClient\Util\WebdavClientUtils;
use Symfony\Component\DomCrawler\Crawler;
use Wa72\HtmlPageDom\HtmlPage;

// TODO: add LOCK, COPY and MOVE methods

interface WebdavClientInterface
{
    public const SORT_ASC = 1;
    public const SORT_DESC = 2;

    public const ALL_PAGES = -1;
    public const DEFAULT_PAGE_SIZE = 20;

    public const CACHE_TTL = 'P1D';

    public const TYPE_FILE = 'file';
    public const TYPE_DIRECTORY = 'directory';

    public const HEADER_DEPTH = 'Depth';
    public const HEADER_ALLOW = 'Allow';

    public const CACHE_KEY_PREFIX = 'webdav_cache';
    public const XML_FILTER = 'multistatus response';

    /**
     * @return null|string[]
     */
    public function getSupportedMethods(): ?array;

    /**
     * @param string $path
     * @param int    $page
     * @param int    $pageSize
     * @param string $sortBy
     * @param int    $sortOrder
     *
     * @return WebdavDto[]
     */
    public function listDirectory(
        string $path,
        int $page = self::ALL_PAGES,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        string $sortBy = WebdavClientUtils::XML_FIELD_DISPLAYNAME,
        int $sortOrder = self::SORT_ASC
    ): array;

    /**
     * @param string $path
     *
     * @return WebdavDtoInterface|null
     */
    public function getPathInfo(string $path): ?WebdavDtoInterface;

    /**
     * @param string $path
     *
     * @return bool
     */
    public function exists(string $path): bool;

    /**
     * @param string $path
     *
     * @return null|string
     */
    public function readFileAsString(string $path): ?string;

    /**
     * @param string $path
     *
     * @return array|null
     */
    public function readFileAsJson(string $path): ?array;

    /**
     * @param string $path
     *
     * @return Crawler
     */
    public function readFileAsCrawler(string $path): ?Crawler;

    /**
     * @param string $path
     *
     * @return HtmlPage
     */
    public function readFileAsHtmlPage(string $path): ?HtmlPage;

    /**
     * @param string $path
     *
     * @return bool
     */
    public function createDirectory(string $path): bool;

    /**
     * @param string $path
     * @param string $contents
     *
     * @return bool
     */
    public function writeToFile(string $path, string $contents): bool;

    /**
     * @param string $path
     *
     * @return bool
     */
    public function delete(string $path): bool;
}
