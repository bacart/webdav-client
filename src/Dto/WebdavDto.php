<?php

namespace Bacart\WebdavClient\Dto;

class WebdavDto
{
    public const DIRECTORY = 'directory';

    /** @var string */
    protected $name;

    /** @var string */
    protected $type;

    /** @var \DateTimeInterface */
    protected $created;

    /** @var \DateTimeInterface */
    protected $modified;

    /** @var string|null */
    protected $etag;

    /** @var int|null */
    protected $size;

    /**
     * @param string             $name
     * @param string             $type
     * @param \DateTimeInterface $created
     * @param \DateTimeInterface $modified
     * @param string|null        $etag
     * @param int|null           $size
     */
    public function __construct(
        string $name,
        string $type,
        \DateTimeInterface $created,
        \DateTimeInterface $modified,
        string $etag = null,
        int $size = null
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->created = $created;
        $this->modified = $modified;
        $this->etag = $etag;
        $this->size = $size;
    }

    /**
     * @return bool
     */
    public function isDirectory(): bool
    {
        return static::DIRECTORY === $this->type;
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

    /**
     * @return null|string
     */
    public function getEtag(): ?string
    {
        return $this->etag;
    }

    /**
     * @return int|null
     */
    public function getSize(): ?int
    {
        return $this->size;
    }
}
