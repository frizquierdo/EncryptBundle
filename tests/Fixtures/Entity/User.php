<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use PSolutions\EncryptBundle\Attributes\BlindIndex;
use PSolutions\EncryptBundle\Attributes\Encrypted;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[Encrypted]
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $email = null;

    #[BlindIndex(source: 'email', normalizer: BlindIndex::LOWER_TRIM)]
    #[ORM\Column(name: 'email_hash', length: 64, nullable: true, unique: true)]
    public ?string $emailHash = null;

    #[Encrypted]
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $plain = null;

    public function __construct(?string $email = null, ?string $name = null)
    {
        $this->email = $email;
        $this->name = $name;
    }
}
