<?php

namespace Bacart\WebdavClient\Dto;

use Bacart\WebdavClient\Client\WebdavClientInterface;

class WebdavDto implements \Serializable
{
    /** @var string */
    protected $name;

    /** @var string */
    protected $type;

    /** @var string */
    protected $etag;

    /** @var int */
    protected $size;

    /** @var \DateTimeInterface */
    protected $created;

    /** @var \DateTimeInterface */
    protected $modified;

    /**
     * @param string             $name
     * @param string             $type
     * @param string             $etag
     * @param int                $size
     * @param \DateTimeInterface $created
     * @param \DateTimeInterface $modified
     */
    public function __construct(
        string $name,
        string $type,
        string $etag,
        int $size,
        \DateTimeInterface $created,
        \DateTimeInterface $modified
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->etag = $etag;
        $this->size = $size;
        $this->created = $created;
        $this->modified = $modified;
    }

    /**
     * {@inheritdoc}
     */
    public function serialize(): string
    {
        return serialize([
            $this->name,
            $this->type,
            $this->etag,
            $this->size,
            $this->created,
            $this->modified,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function unserialize($serialized): void
    {
        [
            $this->name,
            $this->type,
            $this->etag,
            $this->size,
            $this->created,
            $this->modified,
        ] = unserialize($serialized, [
            'allowed_classes' => [
                \DateTime::class,
            ],
        ]);
    }

    /**
     * @return bool
     */
    public function isDirectory(): bool
    {
        return WebdavClientInterface::TYPE_DIRECTORY === $this->type;
    }

    /**
     * @return bool
     */
    public function isFile(): bool
    {
        return !$this->isDirectory();
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getEtag(): string
    {
        return $this->etag;
    }

    /**
     * @return int
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * @return \DateTimeInterface
     */
    public function getCreated(): \DateTimeInterface
    {
        return $this->created;
    }

    /**
     * @return \DateTimeInterface
     */
    public function getModified(): \DateTimeInterface
    {
        return $this->modified;
    }
}
