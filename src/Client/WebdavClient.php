<?php

namespace Bacart\WebdavClient\Client;

use Bacart\GuzzleClient\Client\GuzzleClientInterface;
use Bacart\GuzzleClient\Exception\GuzzleClientException;
use Bacart\WebdavClient\Dto\WebdavDto;
use Bacart\WebdavClient\Exception\WebdavClientException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Wa72\HtmlPageDom\HtmlPageCrawler;

class WebdavClient implements WebdavClientInterface
{
    /** @var GuzzleClientInterface */
    protected $guzzleClient;

    /** @var LoggerInterface|null */
    protected $logger;

    /**
     * @param GuzzleClientInterface $guzzleClient
     * @param LoggerInterface|null  $logger
     */
    public function __construct(
        GuzzleClientInterface $guzzleClient,
        LoggerInterface $logger = null
    ) {
        $this->guzzleClient = $guzzleClient;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $uri): bool
    {
        try {
            $response = $this->guzzleClient->getGuzzleResponse(
                $uri,
                [],
                GuzzleClientInterface::METHOD_PROPFIND
            );

            return WebdavClientInterface::HTTP_MULTI_STATUS === $response->getStatusCode();
        } catch (GuzzleClientException $e) {
            if (null !== $this->logger) {
                $this->logger->notice($e->getMessage());
            }

            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebdavClientException
     */
    public function delete(string $uri): bool
    {
        if (!$this->exists($uri)) {
            return true;
        }

        try {
            $response = $this->guzzleClient->getGuzzleResponse(
                $uri,
                [],
                GuzzleClientInterface::METHOD_DELETE
            );

            return \in_array(
                $response->getStatusCode(),
                WebdavClientInterface::HTTP_DELETED_STATUSES,
                true
            );
        } catch (GuzzleClientException $e) {
            throw new WebdavClientException($e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebdavClientException
     */
    public function listDirectory(string $directory): \Generator
    {
        $directory = trim($directory, '/');

        try {
            $crawler = $this->guzzleClient->getGuzzleResponseAsHtmlPageCrawler(
                $directory,
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

        /** @var WebdavDto[] $webdavDtos */
        $webdavDtos = $crawler
            ->filter(WebdavClientInterface::XML_FILTER)
            ->each(function (Crawler $node): WebdavDto {
                return $this->createWebdavDto($node);
            });

        foreach ($webdavDtos as $webdavDto) {
            if ($webdavDto->getName() !== $directory) {
                yield $webdavDto->getName() => $webdavDto;
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebdavClientException
     */
    public function createDirectory(string $directory): bool
    {
        if ($this->exists($directory)) {
            return true;
        }

        $directory = trim($directory, '/');
        $directoryParts = explode('/', $directory);
        $subdirectory = '';

        foreach ($directoryParts as $directoryPart) {
            $subdirectory .= $directoryPart.'/';

            if (!$this->exists($subdirectory)
                && !$this->directoryCreate($subdirectory)) {
                throw new WebdavClientException(sprintf(
                    'Directory "%s" could not be created',
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
    public function writeToFile(string $filename, string $contents): bool
    {
        $this->createDirectory(\dirname($filename));

        try {
            $response = $this->guzzleClient->getGuzzleResponse(
                $filename,
                [
                    RequestOptions::HEADERS => [
                        WebdavClientInterface::HEADER_CONTENT_LENGTH => mb_strlen($contents),
                        WebdavClientInterface::HEADER_SHA256         => hash('sha256', $contents),
                        WebdavClientInterface::HEADER_ETAG           => md5($contents),
                    ],
                    RequestOptions::BODY => $contents,
                ],
                GuzzleClientInterface::METHOD_PUT
            );

            return WebdavClientInterface::HTTP_CREATED === $response->getStatusCode();
        } catch (GuzzleClientException $e) {
            if (null !== $this->logger) {
                $this->logger->error($e->getMessage());
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebdavClientException
     */
    public function readFileAsString(string $filename): string
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsString($filename);
        } catch (GuzzleClientException $e) {
            throw new WebdavClientException($e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebdavClientException
     */
    public function readFileAsJson(string $filename): ?array
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsJson($filename);
        } catch (GuzzleClientException $e) {
            throw new WebdavClientException($e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebdavClientException
     */
    public function readFileAsHtmlPageCrawler(string $filename): HtmlPageCrawler
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsHtmlPageCrawler($filename);
        } catch (GuzzleClientException $e) {
            throw new WebdavClientException($e);
        }
    }

    /**
     * @param Crawler $node
     *
     * @throws \InvalidArgumentException
     *
     * @return WebdavDto
     */
    protected function createWebdavDto(Crawler $node): WebdavDto
    {
        $name = $node->filter(WebdavClientInterface::XML_DISPLAYNAME)->text();

        if (null === $type = \GuzzleHttp\Psr7\mimetype_from_filename($name)) {
            $type = $node->filter(WebdavClientInterface::XML_GETCONTENTTYPE)->text();
        }

        $created = $node->filter(WebdavClientInterface::XML_CREATIONDATE)->text();
        $modified = $node->filter(WebdavClientInterface::XML_GETLASTMODIFIED)->text();

        $etag = $node->filter(WebdavClientInterface::XML_GETETAG)->text();
        $size = (int) $node->filter(WebdavClientInterface::XML_GETCONTENTLENGTH)->text();

        return new WebdavDto(
            $name,
            $type ?: WebdavDto::DIRECTORY,
            new \DateTime($created),
            new \DateTime($modified),
            $etag,
            $size
        );
    }

    /**
     * @param string $directory
     *
     * @return bool
     */
    protected function directoryCreate(string $directory): bool
    {
        try {
            $response = $this->guzzleClient->getGuzzleResponse(
                $directory,
                [],
                GuzzleClientInterface::METHOD_MKCOL
            );

            return WebdavClientInterface::HTTP_CREATED === $response->getStatusCode();
        } catch (GuzzleClientException $e) {
            if (null !== $this->logger) {
                $this->logger->notice($e->getMessage());
            }

            return false;
        }
    }
}
