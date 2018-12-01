<?php

/*
 * This file is part of the Bacart package.
 *
 * (c) Alex Bacart <alex@bacart.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Bacart\WebdavClient\Dto;

interface WebdavDtoInterface extends \Serializable
{
    /**
     * @return bool
     */
    public function isDirectory(): bool;

    /**
     * @return bool
     */
    public function isFile(): bool;

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @return string
     */
    public function getType(): string;

    /**
     * @return string
     */
    public function getEtag(): string;

    /**
     * @return int
     */
    public function getSize(): int;

    /**
     * @return \DateTimeInterface
     */
    public function getCreated(): \DateTimeInterface;

    /**
     * @return \DateTimeInterface
     */
    public function getModified(): \DateTimeInterface;
}
