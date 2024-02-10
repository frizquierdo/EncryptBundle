<?php

namespace PSolutions\EncryptBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Common\Annotations\AnnotationReader as Reader;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Psr\Log\LoggerInterface;
use PSolutions\EncryptBundle\Encryptors\EncryptorInterface;

/**
 * Doctrine event listeners which encrypt/decrypt entities.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::postUpdate)]
interface DoctrineEncryptListenerInterface {

    public const ENCRYPTED_SUFFIX = '<ENC>';

    public function __construct(
            LoggerInterface $logger,
            Reader $annReader,
            EncryptorInterface $encryptor,
            array $annotationArray,
            bool $isDisabled
    );

    /**
     * Encrypt the password before it is written to the database.
     *
     * Notice that we do not recalculate changes otherwise the value will be written
     * every time (Because it is going to differ from the un-encrypted value)
     */
    public function onFlush(OnFlushEventArgs $args): void;

    /**
     * After we have persisted the entities, we want to have the
     * decrypted information available once more.
     */
    public function postUpdate(PostUpdateEventArgs $args): void;

    /**
     * Listen a postLoad lifecycle event. Checking and decrypt entities
     * which have #[Encrypted] attribute.
     */
    public function postLoad(PostLoadEventArgs $args): void;

}
