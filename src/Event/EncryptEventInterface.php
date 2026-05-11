<?php

namespace PSolutions\EncryptBundle\Event;

interface EncryptEventInterface
{
    public function getValue(): ?string;

    public function setValue(?string $value): void;
}
