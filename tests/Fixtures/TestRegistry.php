<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;

final class TestRegistry extends AbstractManagerRegistry
{
    /** @param array<string, EntityManagerInterface> $managers */
    public function __construct(private array $services)
    {
        $names = array_combine(array_keys($services), array_keys($services));
        parent::__construct('ORM', $names, $names, (string) array_key_first($services), (string) array_key_first($services), 'Doctrine\Persistence\Proxy');
    }

    protected function getService(string $name): object
    {
        return $this->services[$name];
    }

    protected function resetService(string $name): void
    {
    }
}
