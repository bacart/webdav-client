<?php

/*
 * This file is part of the Bacart package.
 *
 * (c) Alex Bacart <alex@bacart.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Bacart\WebdavClient\Client;

use Bacart\Common\Exception\MissingPackageException;
use Bacart\GuzzleClient\Client\GuzzleClientInterface;
use Bacart\GuzzleClient\Exception\GuzzleClientException;
use Bacart\WebdavClient\Dto\WebdavDtoInterface;
use Bacart\WebdavClient\Exception\WebdavClientException;
use Bacart\WebdavClient\Util\WebdavClientUtils;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;
use Wa72\HtmlPageDom\HtmlPage;

class WebdavClient extends AbstractWebdavClient
{
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
            $this->logException($e);

            return null;
        }

        $allow = $response->hasHeader(WebdavClientInterface::HEADER_ALLOW)
            ? $response->getHeader(WebdavClientInterface::HEADER_ALLOW)[0] ?? ''
            : '';

        $result = array_map(
            'trim',
            explode(',', $allow)
        );

        return array_filter($result);
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
        string $sortBy = WebdavClientUtils::XML_FIELD_DISPLAYNAME,
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

            $href = WebdavClientUtils::getCrawlerXmlFieldValue(
                $crawler,
                WebdavClientUtils::XML_FIELD_HREF
            );

            $name = WebdavClientUtils::getCrawlerXmlFieldValue(
                $crawler,
                WebdavClientUtils::XML_FIELD_DISPLAYNAME
            );

            $type = WebdavClientUtils::getCrawlerXmlFieldValue(
                $crawler,
                WebdavClientUtils::XML_FIELD_GETCONTENTTYPE
            );

            if (!$this->fileIsValid($path, $href, $name, $type)) {
                continue;
            }

            if (WebdavClientInterface::TYPE_DIRECTORY !== $type) {
                $type = WebdavClientInterface::TYPE_FILE;
            }

            $key = WebdavClientUtils::XML_FIELD_DISPLAYNAME !== $sortBy
                ? WebdavClientUtils::getCrawlerXmlFieldValue($crawler, $sortBy).'|'.$name
                : $name;

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

        $crawlers = array_merge(
            $items[WebdavClientInterface::TYPE_DIRECTORY],
            $items[WebdavClientInterface::TYPE_FILE]
        );

        if ($page > WebdavClientInterface::ALL_PAGES) {
            $crawlers = array_chunk($crawlers, $pageSize)[$page] ?? [];
        }

        $result = [];

        foreach ($crawlers as $crawler) {
            $webdavDtoArguments = WebdavClientUtils::getWebdavDtoArguments($crawler);
            $webdavDto = $this->createWebdavDto(...$webdavDtoArguments);
            $webdavDtoPath = $path.'/'.$webdavDto->getName();

            $cachedWebdavDto = $this->getFromCache($webdavDtoPath, function (): ?WebdavDtoInterface {
                return null;
            });

            if (null === $cachedWebdavDto
                || serialize($cachedWebdavDto) !== serialize($webdavDto)) {
                $this->saveToCache($webdavDto, $webdavDtoPath);
            }

            $result[] = $webdavDto;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getPathInfo(string $path): ?WebdavDtoInterface
    {
        return $this->getFromCache($path, function (string $path): ?WebdavDtoInterface {
            try {
                $node = $this
                    ->getHtmlPageCrawler($path)
                    ->filter(WebdavClientInterface::XML_FILTER);
            } catch (WebdavClientException $e) {
                $this->logException($e, $path);

                return null;
            }

            if (0 === $node->count()) {
                return null;
            }

            try {
                $webdavDtoArguments = WebdavClientUtils::getWebdavDtoArguments($node->first());
                $webdavDto = $this->createWebdavDto(...$webdavDtoArguments);
            } catch (WebdavClientException $e) {
                $this->logException($e, $path);
                $webdavDto = null;
            }

            if (null !== $webdavDto) {
                $this->saveToCache($webdavDto, $path);
            }

            return $webdavDto;
        });
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
        if (null !== $webdavDto = $this->getPathInfo($path)) {
            return $webdavDto->isDirectory();
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
                    'Can not create directory "%s"',
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
    public function writeToFile(string $path, $contents): bool
    {
        if (!$this->createDirectory(\dirname($path))) {
            return false;
        }

        try {
            $response = $this->guzzleClient->getGuzzleResponse(
                ltrim($path, '/'),
                [
                    RequestOptions::BODY => $contents,
                ],
                GuzzleClientInterface::METHOD_PUT
            );

            $result = GuzzleClientInterface::HTTP_CREATED === $response->getStatusCode();
        } catch (GuzzleClientException $e) {
            $this->logException($e, $path);
            $result = false;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): bool
    {
        if (!$this->exists($path)) {
            return true;
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

            $result = \in_array(
                $response->getStatusCode(),
                $deletedStatuses,
                true
            );
        } catch (GuzzleClientException $e) {
            $this->logException($e, $path);
            $result = false;
        }

        if ($result) {
            $this->deleteFromCache($path);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function downloadFile(string $path, string $filename): bool
    {
        try {
            $this->guzzleClient->writeGuzzleResponseToFile($path, $filename);
            $result = true;
        } catch (GuzzleClientException $e) {
            $this->logException($e, $path);
            $result = false;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function readFileAsString(string $path): ?string
    {
        try {
            $result = $this->guzzleClient->getGuzzleResponseAsString($path);
        } catch (GuzzleClientException $e) {
            $this->logException($e, $path);
            $result = null;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function readFileAsJson(string $path): ?array
    {
        try {
            $result = $this->guzzleClient->getGuzzleResponseAsJson($path);
        } catch (GuzzleClientException $e) {
            $this->logException($e, $path);
            $result = null;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function readFileAsCrawler(string $path): ?Crawler
    {
        try {
            $result = $this->guzzleClient->getGuzzleResponseAsCrawler($path);
        } catch (GuzzleClientException | MissingPackageException $e) {
            $this->logException($e, $path);
            $result = null;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function readFileAsHtmlPage(string $path): ?HtmlPage
    {
        try {
            $result = $this->guzzleClient->getGuzzleResponseAsHtmlPage($path);
        } catch (GuzzleClientException | MissingPackageException $e) {
            $this->logException($e, $path);
            $result = null;
        }

        return $result;
    }
}
