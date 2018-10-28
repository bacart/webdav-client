<?php

namespace Bacart\WebdavClient\Dto;

use Bacart\WebdavClient\Client\WebdavClientInterface;

class WebdavDto implements WebdavDtoInterface
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
     * {@inheritdoc}
     */
    public function isDirectory(): bool
    {
        return WebdavClientInterface::TYPE_DIRECTORY === $this->type;
    }

    /**
     * {@inheritdoc}
     */
    public function isFile(): bool
    {
        return !$this->isDirectory();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     */
    public function getEtag(): string
    {
        return $this->etag;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * {@inheritdoc}
     */
    public function getCreated(): \DateTimeInterface
    {
        return $this->created;
    }

    /**
     * {@inheritdoc}
     */
    public function getModified(): \DateTimeInterface
    {
        return $this->modified;
    }
}
