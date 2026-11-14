<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use PSolutions\EncryptBundle\BlindIndex\BlindIndexer;
use PSolutions\EncryptBundle\Exception\EncryptException;
use PSolutions\EncryptBundle\Tests\Fixtures\Entity\User;
use PSolutions\EncryptBundle\Tests\Fixtures\Invalid\InvalidEncryptedIndex;
use PSolutions\EncryptBundle\Tests\Fixtures\OrmTestCase;

class ListenerBlindIndexTest extends OrmTestCase
{
    public function testInsertComputesIndexFromPlainValue(): void
    {
        $user = $this->insertUser(' John@Example.com ', 'John');
        $row = $this->row($user->id);

        $this->assertStringEndsWith('<ENC>', $row['email']);
        $this->assertSame($this->indexer->compute('john@example.com'), $row['email_hash']);
        // The entity in memory keeps the plain value.
        $this->assertSame(' John@Example.com ', $user->email);
        $this->assertSame($row['email_hash'], $user->emailHash);
    }

    /** Regression (v5.0.0): on insert only the last #[Encrypted] field was encrypted, the others were stored plain. */
    public function testInsertEncryptsEveryEncryptedField(): void
    {
        $user = $this->insertUser('a@x.com', 'Ana');
        $row = $this->row($user->id);

        $this->assertSame('a@x.com', $this->encryptor->decrypt($row['email']));
        $this->assertStringEndsWith('<ENC>', $row['email']);
        $this->assertSame('Ana', $this->encryptor->decrypt($row['name']));
        $this->assertStringEndsWith('<ENC>', $row['name']);

        // A second flush without changes must not rewrite the row (no change against the encrypted original).
        $this->em->flush();
        $this->assertSame($row, $this->row($user->id));
        $this->assertSame([], $this->em->getUnitOfWork()->getEntityChangeSet($user));
    }

    public function testUpdateOfSourceRecomputesIndex(): void
    {
        $user = $this->reload($this->insertUser('a@example.com'));
        $user->email = 'B@example.com';
        $this->em->flush();

        $this->assertSame($this->indexer->compute('b@example.com'), $this->row($user->id)['email_hash']);
        $this->assertSame('B@example.com', $user->email);

        $user = $this->reload($user);
        $user->email = null;
        $this->em->flush();
        $this->assertNull($this->row($user->id)['email_hash']);
    }

    public function testUpdateOfOtherFieldDoesNotChangeIndex(): void
    {
        $user = $this->reload($this->insertUser('a@example.com'));
        $user->plain = 'changed';
        $this->em->flush();

        $this->assertSame($this->indexer->compute('a@example.com'), $this->row($user->id)['email_hash']);
        $this->assertSame('a@example.com', $this->reload($user)->email);
    }

    public function testQueryThroughHash(): void
    {
        $this->insertUser('john@example.com');
        $this->em->clear();

        $found = $this->em->getRepository(User::class)->findOneBy([
            'emailHash' => $this->indexer->hash(User::class, 'emailHash', ' JOHN@example.com'),
        ]);

        $this->assertNotNull($found);
        $this->assertSame('john@example.com', $found->email);
    }

    public function testHashOfUnknownPropertyThrows(): void
    {
        $this->expectException(EncryptException::class);
        $this->indexer->hash(User::class, 'email', 'x');
    }

    public function testDisabledEncryptionStillComputesIndex(): void
    {
        $this->boot(self::KEY, null, true);
        $user = $this->insertUser('john@example.com');
        $row = $this->row($user->id);

        $this->assertSame('john@example.com', $row['email']);
        $this->assertSame($this->indexer->compute('john@example.com'), $row['email_hash']);
    }

    public function testEncryptedBlindIndexThrows(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../Fixtures/Invalid'], true);
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('PSolutionsEncryptInvalidProxies');
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        $indexer = new BlindIndexer(null, null, self::KEY);

        $this->expectException(EncryptException::class);
        $this->expectExceptionMessageMatches('/cannot be #\[Encrypted\]/');
        $indexer->getBlindIndexes($em->getClassMetadata(InvalidEncryptedIndex::class));
    }
}
