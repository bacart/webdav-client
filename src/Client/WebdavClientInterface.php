<?php

namespace Bacart\WebdavClient\Client;

use Bacart\WebdavClient\Dto\WebdavDto;
use Wa72\HtmlPageDom\HtmlPageCrawler;

// TODO: add LOCK, COPY and MOVE methods

interface WebdavClientInterface
{
    public const DIRECTORY_LIST_CACHE_ITEM_PREFIX = '//';

    public const SORT_ASC = 1;
    public const SORT_DESC = 2;

    public const ALL_PAGES = -1;
    public const DEFAULT_PAGE_SIZE = 3;

    public const TYPE_FILE = 'file';
    public const TYPE_DIRECTORY = 'directory';

    public const HEADER_DEPTH = 'Depth';
    public const HEADER_ALLOW = 'Allow';

    public const XML_FILTER = 'multistatus response';
    public const XML_FIELDS_PREFIX = 'propstat prop ';

    public const XML_FIELD_HREF = 'href';
    public const XML_FIELD_GETETAG = self::XML_FIELDS_PREFIX.'getetag';
    public const XML_FIELD_DISPLAYNAME = self::XML_FIELDS_PREFIX.'displayname';
    public const XML_FIELD_CREATIONDATE = self::XML_FIELDS_PREFIX.'creationdate';
    public const XML_FIELD_GETCONTENTTYPE = self::XML_FIELDS_PREFIX.'getcontenttype';
    public const XML_FIELD_GETLASTMODIFIED = self::XML_FIELDS_PREFIX.'getlastmodified';
    public const XML_FIELD_GETCONTENTLENGTH = self::XML_FIELDS_PREFIX.'getcontentlength';

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
        string $sortBy = self::XML_FIELD_DISPLAYNAME,
        int $sortOrder = self::SORT_ASC
    ): array;

    /**
     * @param string $path
     *
     * @return WebdavDto|null
     */
    public function getPathInfo(string $path): ?WebdavDto;

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
     * @return HtmlPageCrawler
     */
    public function readFileAsHtmlPageCrawler(string $path): ?HtmlPageCrawler;

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
