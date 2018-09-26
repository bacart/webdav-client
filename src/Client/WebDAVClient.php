<?php

namespace Bacart\WebDAVClient\Client;

use Bacart\GuzzleClient\Client\GuzzleClientInterface;
use Bacart\GuzzleClient\Exception\GuzzleClientException;
use Bacart\WebDAVClient\Dto\WebDAVDto;
use Bacart\WebDAVClient\Exception\WebDAVClientException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Wa72\HtmlPageDom\HtmlPageCrawler;

class WebDAVClient implements WebDAVClientInterface
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

            return WebDAVClientInterface::HTTP_MULTI_STATUS === $response->getStatusCode();
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
     * @throws WebDAVClientException
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
                WebDAVClientInterface::HTTP_DELETED_STATUSES,
                true
            );
        } catch (GuzzleClientException $e) {
            throw new WebDAVClientException($e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebDAVClientException
     */
    public function listDirectory(string $directory): \Generator
    {
        $directory = trim($directory, '/');

        try {
            $crawler = $this->guzzleClient->getGuzzleResponseAsHtmlPageCrawler(
                $directory,
                [
                    RequestOptions::HEADERS => [
                        WebDAVClientInterface::HEADER_DEPTH => 1,
                    ],
                ],
                GuzzleClientInterface::METHOD_PROPFIND
            );
        } catch (GuzzleClientException $e) {
            throw new WebDAVClientException($e);
        }

        /** @var WebDAVDto[] $webDavDtos */
        $webDavDtos = $crawler
            ->filter(WebDAVClientInterface::XML_FILTER)
            ->each(function (Crawler $node): WebDAVDto {
                return $this->createWebDAVDto($node);
            });

        foreach ($webDavDtos as $webDavDto) {
            if ($webDavDto->getName() !== $directory) {
                yield $webDavDto->getName() => $webDavDto;
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebDAVClientException
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
                throw new WebDAVClientException(sprintf(
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
     * @throws WebDAVClientException
     */
    public function writeToFile(string $filename, string $contents): bool
    {
        $this->createDirectory(\dirname($filename));

        try {
            $response = $this->guzzleClient->getGuzzleResponse(
                $filename,
                [
                    RequestOptions::HEADERS => [
                        WebDAVClientInterface::HEADER_CONTENT_LENGTH => mb_strlen($contents),
                        WebDAVClientInterface::HEADER_SHA256         => hash('sha256', $contents),
                        WebDAVClientInterface::HEADER_ETAG           => md5($contents),
                    ],
                    RequestOptions::BODY => $contents,
                ],
                GuzzleClientInterface::METHOD_PUT
            );

            return WebDAVClientInterface::HTTP_CREATED === $response->getStatusCode();
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
     * @throws WebDAVClientException
     */
    public function readFileAsString(string $filename): string
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsString($filename);
        } catch (GuzzleClientException $e) {
            throw new WebDAVClientException($e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebDAVClientException
     */
    public function readFileAsJson(string $filename): ?array
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsJson($filename);
        } catch (GuzzleClientException $e) {
            throw new WebDAVClientException($e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebDAVClientException
     */
    public function readFileAsHtmlPageCrawler(string $filename): HtmlPageCrawler
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsHtmlPageCrawler($filename);
        } catch (GuzzleClientException $e) {
            throw new WebDAVClientException($e);
        }
    }

    /**
     * @param Crawler $node
     *
     * @throws \InvalidArgumentException
     *
     * @return WebDAVDto
     */
    protected function createWebDAVDto(Crawler $node): WebDAVDto
    {
        $name = $node->filter(WebDAVClientInterface::XML_DISPLAYNAME)->text();

        if (null === $type = \GuzzleHttp\Psr7\mimetype_from_filename($name)) {
            $type = $node->filter(WebDAVClientInterface::XML_GETCONTENTTYPE)->text();
        }

        $created = $node->filter(WebDAVClientInterface::XML_CREATIONDATE)->text();
        $modified = $node->filter(WebDAVClientInterface::XML_GETLASTMODIFIED)->text();

        $etag = $node->filter(WebDAVClientInterface::XML_GETETAG)->text();
        $size = (int) $node->filter(WebDAVClientInterface::XML_GETCONTENTLENGTH)->text();

        return new WebDAVDto(
            $name,
            $type ?: WebDAVDto::DIRECTORY,
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

            return WebDAVClientInterface::HTTP_CREATED === $response->getStatusCode();
        } catch (GuzzleClientException $e) {
            if (null !== $this->logger) {
                $this->logger->notice($e->getMessage());
            }

            return false;
        }
    }
}
