<?php

namespace Bacart\WebdavClient\Util;

use Bacart\Common\Util\ClassUtils;
use Bacart\WebdavClient\Client\WebdavClientInterface;
use Bacart\WebdavClient\Exception\WebdavClientException;
use Symfony\Component\DomCrawler\Crawler;
use function GuzzleHttp\Psr7\mimetype_from_filename;

class WebdavClientUtils
{
    public const XML_FIELDS_PREFIX = 'propstat prop ';

    public const XML_FIELD_HREF = 'href';
    public const XML_FIELD_GETETAG = self::XML_FIELDS_PREFIX.'getetag';
    public const XML_FIELD_DISPLAYNAME = self::XML_FIELDS_PREFIX.'displayname';
    public const XML_FIELD_CREATIONDATE = self::XML_FIELDS_PREFIX.'creationdate';
    public const XML_FIELD_GETCONTENTTYPE = self::XML_FIELDS_PREFIX.'getcontenttype';
    public const XML_FIELD_GETLASTMODIFIED = self::XML_FIELDS_PREFIX.'getlastmodified';
    public const XML_FIELD_GETCONTENTLENGTH = self::XML_FIELDS_PREFIX.'getcontentlength';

    /** @var string[] */
    protected static $xmlFieldTypes = [];

    /**
     * @return string[]
     */
    public static function getXmlFieldTypes(): array
    {
        if (empty(static::$xmlFieldTypes)) {
            static::$xmlFieldTypes = ClassUtils::getClassConstants(
                static::class,
                'XML_FIELD_'
            );
        }

        return static::$xmlFieldTypes;
    }

    /**
     * @param Crawler $crawler
     * @param string  $xmlFieldType
     *
     * @throws WebdavClientException
     *
     * @return string
     */
    public static function getCrawlerXmlFieldValue(
        Crawler $crawler,
        string $xmlFieldType
    ): string {
        if (!\in_array($xmlFieldType, static::getXmlFieldTypes(), true)) {
            throw new WebdavClientException(sprintf(
                'Invalid XML field type "%s"',
                $xmlFieldType
            ));
        }

        try {
            $field = $crawler->filter($xmlFieldType);

            $result = $field->count()
                ? trim($field->text())
                : '';
        } catch (\InvalidArgumentException $e) {
            throw new WebdavClientException($e);
        }

        if (static::XML_FIELD_GETCONTENTTYPE !== $xmlFieldType) {
            return $result;
        }

        $name = static::getCrawlerXmlFieldValue(
            $crawler,
            static::XML_FIELD_DISPLAYNAME
        );

        $type = mimetype_from_filename($name) ?: $result;

        return $type ?: WebdavClientInterface::TYPE_DIRECTORY;
    }

    /**
     * @param Crawler $crawler
     *
     * @throws WebdavClientException
     *
     * @return string[]
     */
    public static function getWebdavDtoArguments(Crawler $crawler): array
    {
        $name = static::getCrawlerXmlFieldValue(
            $crawler,
            static::XML_FIELD_DISPLAYNAME
        );

        $type = static::getCrawlerXmlFieldValue(
            $crawler,
            static::XML_FIELD_GETCONTENTTYPE
        );

        $created = static::getCrawlerXmlFieldValue(
            $crawler,
            static::XML_FIELD_CREATIONDATE
        );

        $modified = static::getCrawlerXmlFieldValue(
            $crawler,
            static::XML_FIELD_GETLASTMODIFIED
        );

        $etag = static::getCrawlerXmlFieldValue(
            $crawler,
            static::XML_FIELD_GETETAG
        );

        $size = static::getCrawlerXmlFieldValue(
            $crawler,
            static::XML_FIELD_GETCONTENTLENGTH
        );

        return [
            $name,
            $type,
            $etag,
            $size,
            $created,
            $modified,
        ];
    }
}
