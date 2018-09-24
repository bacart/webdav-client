<?php

namespace Bacart\WebdavClient\Client;

use Bacart\GuzzleClient\Client\GuzzleClientInterface;
use Bacart\GuzzleClient\Exception\GuzzleClientException;
use Bacart\WebdavClient\Dto\WebDavDto;
use Bacart\WebdavClient\Exception\WebDavClientException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Wa72\HtmlPageDom\HtmlPageCrawler;

class WebDavClient implements WebDavClientInterface
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

            return WebDavClientInterface::HTTP_MULTI_STATUS === $response->getStatusCode();
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
     * @throws WebDavClientException
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
                WebDavClientInterface::HTTP_DELETED_STATUSES,
                true
            );
        } catch (GuzzleClientException $e) {
            throw new WebDavClientException($e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebDavClientException
     */
    public function listDirectory(string $directory): \Generator
    {
        $directory = trim($directory, '/');

        try {
            $crawler = $this->guzzleClient->getGuzzleResponseAsHtmlPageCrawler(
                $directory,
                [
                    RequestOptions::HEADERS => [
                        WebDavClientInterface::HEADER_DEPTH => 1,
                    ],
                ],
                GuzzleClientInterface::METHOD_PROPFIND
            );
        } catch (GuzzleClientException $e) {
            throw new WebDavClientException($e);
        }

        /** @var WebDavDto[] $webDavDtos */
        $webDavDtos = $crawler
            ->filter(WebDavClientInterface::XML_FILTER)
            ->each(function (Crawler $node): WebDavDto {
                return $this->createWebDavDto($node);
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
     * @throws WebDavClientException
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
                throw new WebDavClientException(sprintf(
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
     * @throws WebDavClientException
     */
    public function writeToFile(string $filename, string $contents): bool
    {
        $this->createDirectory(\dirname($filename));

        try {
            $response = $this->guzzleClient->getGuzzleResponse(
                $filename,
                [
                    RequestOptions::HEADERS => [
                        WebDavClientInterface::HEADER_CONTENT_LENGTH => mb_strlen($contents),
                        WebDavClientInterface::HEADER_SHA256         => hash('sha256', $contents),
                        WebDavClientInterface::HEADER_ETAG           => md5($contents),
                    ],
                    RequestOptions::BODY => $contents,
                ],
                GuzzleClientInterface::METHOD_PUT
            );

            return WebDavClientInterface::HTTP_CREATED === $response->getStatusCode();
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
     * @throws WebDavClientException
     */
    public function readFileAsString(string $filename): string
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsString($filename);
        } catch (GuzzleClientException $e) {
            throw new WebDavClientException($e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebDavClientException
     */
    public function readFileAsJson(string $filename): ?array
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsJson($filename);
        } catch (GuzzleClientException $e) {
            throw new WebDavClientException($e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws WebDavClientException
     */
    public function readFileAsHtmlPageCrawler(string $filename): HtmlPageCrawler
    {
        try {
            return $this->guzzleClient->getGuzzleResponseAsHtmlPageCrawler($filename);
        } catch (GuzzleClientException $e) {
            throw new WebDavClientException($e);
        }
    }

    /**
     * @param Crawler $node
     *
     * @throws \InvalidArgumentException
     *
     * @return WebDavDto
     */
    protected function createWebDavDto(Crawler $node): WebDavDto
    {
        $name = $node->filter(WebDavClientInterface::XML_DISPLAYNAME)->text();

        if (null === $type = \GuzzleHttp\Psr7\mimetype_from_filename($name)) {
            $type = $node->filter(WebDavClientInterface::XML_GETCONTENTTYPE)->text();
        }

        $created = $node->filter(WebDavClientInterface::XML_CREATIONDATE)->text();
        $modified = $node->filter(WebDavClientInterface::XML_GETLASTMODIFIED)->text();

        $etag = $node->filter(WebDavClientInterface::XML_GETETAG)->text();
        $size = (int) $node->filter(WebDavClientInterface::XML_GETCONTENTLENGTH)->text();

        return new WebDavDto(
            $name,
            $type ?: WebDavDto::DIRECTORY,
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

            return WebDavClientInterface::HTTP_CREATED === $response->getStatusCode();
        } catch (GuzzleClientException $e) {
            if (null !== $this->logger) {
                $this->logger->notice($e->getMessage());
            }

            return false;
        }
    }
}
