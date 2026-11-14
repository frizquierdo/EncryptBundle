<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Tests\Fixtures\Invalid;

use Doctrine\ORM\Mapping as ORM;
use PSolutions\EncryptBundle\Attributes\BlindIndex;
use PSolutions\EncryptBundle\Attributes\Encrypted;

/**
 * Invalid on purpose: a blind index cannot be encrypted. Lives outside the fixtures entity namespace/path scan.
 */
#[ORM\Entity]
class InvalidEncryptedIndex
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id = 1;

    #[ORM\Column(nullable: true)]
    public ?string $email = null;

    #[Encrypted]
    #[BlindIndex(source: 'email')]
    #[ORM\Column(nullable: true)]
    public ?string $emailHash = null;
}
