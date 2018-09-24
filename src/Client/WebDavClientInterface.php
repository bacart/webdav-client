<?php

namespace Bacart\WebdavClient\Client;

use Bacart\GuzzleClient\Client\GuzzleClientInterface;
use Bacart\WebdavClient\Dto\WebDavDto;
use Psr\Log\LoggerInterface;
use Wa72\HtmlPageDom\HtmlPageCrawler;

// TODO: implement COPY and MOVE

interface WebDavClientInterface
{
    public const HTTP_CREATED = 201;
    public const HTTP_MULTI_STATUS = 207;
    public const HTTP_DELETE_STATUSES = [200, 204];

    public const HEADER_DEPTH = 'Depth';

    public const HEADER_AUTHORIZATION = 'Authorization';
    public const HEADER_CONTENT_LENGTH = 'Content-Length';
    public const HEADER_SHA256 = 'Sha256';
    public const HEADER_ETAG = 'Etag';

    public const XML_GETETAG = 'getetag';
    public const XML_DISPLAYNAME = 'displayname';
    public const XML_CREATIONDATE = 'creationdate';
    public const XML_GETCONTENTTYPE = 'getcontenttype';
    public const XML_GETLASTMODIFIED = 'getlastmodified';
    public const XML_GETCONTENTLENGTH = 'getcontentlength';
    public const XML_FILTER = 'multistatus response propstat prop';

    /**
     * @param GuzzleClientInterface $guzzleClient
     * @param LoggerInterface|null  $logger
     */
    public function __construct(
        GuzzleClientInterface $guzzleClient,
        LoggerInterface $logger = null
    );

    /**
     * @param string $uri
     *
     * @return bool
     */
    public function exists(string $uri): bool;

    /**
     * @param string $uri
     *
     * @return bool
     */
    public function delete(string $uri): bool;

    /**
     * @param string $directory
     *
     * @return \Generator|WebDavDto[]
     */
    public function listDirectory(string $directory): \Generator;

    /**
     * @param string $directory
     *
     * @return bool
     */
    public function createDirectory(string $directory): bool;

    /**
     * @param string $filename
     * @param string $contents
     *
     * @return bool
     */
    public function writeToFile(string $filename, string $contents): bool;

    /**
     * @param string $filename
     *
     * @return string
     */
    public function readFileAsString(string $filename): string;

    /**
     * @param string $filename
     *
     * @return array|null
     */
    public function readFileAsJson(string $filename): ?array;

    /**
     * @param string $filename
     *
     * @return HtmlPageCrawler
     */
    public function readFileAsHtmlPageCrawler(string $filename): HtmlPageCrawler;
}
