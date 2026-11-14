<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Tests\Fixtures;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use PSolutions\EncryptBundle\BlindIndex\BlindIndexer;
use PSolutions\EncryptBundle\Encryptors\OpenSslEncryptor;
use PSolutions\EncryptBundle\EventListener\DoctrineEncryptListener;
use PSolutions\EncryptBundle\Maintenance\DatabaseRewriter;
use PSolutions\EncryptBundle\Tests\Fixtures\Entity\User;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * SQLite in-memory entity manager with the bundle listener wired as in config/services.yaml.
 * Requires pdo_sqlite.
 */
abstract class OrmTestCase extends TestCase
{
    public const KEY = 'YBmNcBGfrZoayB+V254wdYa/abvxSUWJsjCtlMc1tRI=';
    public const KEY2 = 'q1v3l0r4b9zXk0m8qYp2b6dJ3eW5tH7uC9aN1sF0gLk=';
    public const BLIND_KEY = 'Zm9vYmFyYmF6cXV4Zm9vYmFyYmF6cXV4Zm9vYmFyYmE=';

    protected EntityManagerInterface $em;
    protected OpenSslEncryptor $encryptor;
    protected BlindIndexer $indexer;
    protected DoctrineEncryptListener $listener;
    protected TestRegistry $registry;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required.');
        }

        $this->boot(self::KEY, null);
    }

    protected function boot(string $encryptKey, ?string $blindIndexKey, bool $isDisabled = false, ?EntityManagerInterface $reuse = null): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/Entity'], true);
        if (method_exists($config, 'enableNativeLazyObjects') && \PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/psolutions-encrypt-proxies');
            $config->setProxyNamespace('PSolutionsEncryptTestProxies');
        }

        $connection = $reuse?->getConnection() ?? DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($connection, $config);

        $this->encryptor = new OpenSslEncryptor(new EventDispatcher());
        $this->encryptor->setSecretKey($encryptKey);
        $this->registry = new TestRegistry(['default' => $this->em]);
        $this->indexer = new BlindIndexer($this->registry, new EventDispatcher(), $encryptKey, $blindIndexKey);
        $this->listener = new DoctrineEncryptListener($this->encryptor, [\PSolutions\EncryptBundle\Attributes\Encrypted::class], $isDisabled, $this->indexer);

        $this->em->getEventManager()->addEventListener([Events::onFlush, Events::postLoad, Events::postUpdate], $this->listener);

        if (null === $reuse) {
            (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        }
    }

    protected function rewriter(): DatabaseRewriter
    {
        return new DatabaseRewriter($this->indexer);
    }

    /** @return array<string, mixed>|false */
    protected function row(int $id): array|false
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM users WHERE id = ?', [$id]);
    }

    protected function insertUser(?string $email, ?string $name = null): User
    {
        $user = new User($email, $name);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function reload(User $user): User
    {
        $id = $user->id;
        $this->em->clear();

        return $this->em->find(User::class, $id);
    }
}
