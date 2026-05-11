<?php

namespace PSolutions\EncryptBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use PSolutions\EncryptBundle\Encryptors\EncryptorInterface;
use PSolutions\EncryptBundle\Exception\EncryptException;
use ReflectionProperty;

/**
 * Doctrine event listeners which encrypt/decrypt entities.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::postUpdate)]
class DoctrineEncryptListener implements DoctrineEncryptListenerInterface {

    /**
     * Encryptor interface namespace.
     */
    public const ENCRYPTOR_INTERFACE_NS = EncryptorInterface::class;

    /**
     * An array of class attribute names that mark properties for encryption.
     * The default is the bundle's Encrypted attribute.
     */
    private array $encryptedAttributes;

    /**
     * Caches information on an entity's encrypted fields in an array keyed on
     * the entity's class name. The value will be a list of Reflected fields
     * that are encrypted.
     */
    protected array $encryptedFieldCache = [];
    private array $rawValues = [];
    private bool $isDisabled;

    public function __construct(
            private readonly LoggerInterface $logger,
            private readonly EncryptorInterface $encryptor,
            array $encryptedAttributes,
            bool $isDisabled
    ) {
        $this->encryptedAttributes = $encryptedAttributes;
        $this->isDisabled = $isDisabled;
    }

    public function getEncryptor(): EncryptorInterface {
        return $this->encryptor;
    }

    /**
     * Set Is Disabled.
     *
     * Used to programmatically disable encryption on flush operations.
     * Decryption still occurs if values have the <ENC> suffix.
     */
    public function setIsDisabled(
        ?bool $isDisabled = true
    ): DoctrineEncryptListenerInterface {
        $this->isDisabled = $isDisabled;

        return $this;
    }

    /**
     * @throws EncryptException
     */
    public function onFlush(OnFlushEventArgs $args): void {
        if ($this->isDisabled) {
            return;
        }

        $em = $args->getObjectManager();
        $unitOfWork = $em->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $this->processFields($entity, $em, true, true);
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $this->processFields($entity, $em, true, false);
        }
    }

    /**
     * Listen a postLoad lifecycle event. Checking and decrypt entities
     * which have @Encrypted annotations.
     *
     * @throws EncryptException
     */
    public function postLoad(PostLoadEventArgs $args): void {
        $entity = $args->getObject();
        // Decrypt the entity fields.
        $this->processFields($entity, $args->getObjectManager(), false, false);
    }

    /**
     * Decrypt a value.
     *
     * If the value is an object, or if it does not contain the suffic <ENC> then return the value iteslf back.
     * Otherwise, decrypt the value and return.
     */
    public function decryptValue(?string $value): ?string {
        // Else decrypt value and return.
        return $this->encryptor->decrypt($value);
    }

    public function getEncryptionableProperties(array $allProperties): array {
        $encryptedFields = [];

        foreach ($allProperties as $refProperty) {
            if ($this->isEncryptedProperty($refProperty)) {
                $encryptedFields[] = $refProperty;
            }
        }

        return $encryptedFields;
    }

/**
     * Process (encrypt/decrypt) entities fields.
     */
    protected function processFields(
        object $entity,
        EntityManagerInterface $em,
        bool $isEncryptOperation,
        bool $isInsert
    ): bool {
        // Get the encrypted properties in the entity.
        $properties = $this->getEncryptedFields($entity, $em);

        // If no encrypted properties, return false.
        if (empty($properties)) {
            return false;
        }

        $unitOfWork = $em->getUnitOfWork();
        $oid = spl_object_id($entity);
        $className = get_class($entity);

        foreach ($properties as $key => $propertyAccessor) {
            // Get the value in the entity.
            $value = $propertyAccessor->getValue($entity);

            // Skip any null values.
            if (null === $value) {
                continue;
            }

            if (is_object($value)) {
                throw new EncryptException(
                    sprintf('Cannot encrypt an object at %s:%s',
                        $className,
                        $key
                    ),
                    $value
                );
            }

            // Encryption is fired by onFlush event, else it is an onLoad event.
            if ($isEncryptOperation) {
                $changeSet = $unitOfWork->getEntityChangeSet($entity);

                // Encrypt value only if change has been detected by Doctrine (comparing unencrypted values, see postLoad flow)
                if (isset($changeSet[$key])) {
                    $encryptedValue = $this->encryptor->encrypt($value);
                    $propertyAccessor->setValue($entity, $encryptedValue);
                    $unitOfWork->recomputeSingleEntityChangeSet(
                        $em->getClassMetadata($className),
                        $entity
                    );

                    if ($isInsert) {
                        // Restore the decrypted value after the change set update
                        $propertyAccessor->setValue($entity, $value);
                    } else {
                        // Will be restored during postUpdate cycle
                        $this->rawValues[$oid][$key] = $value;
                    }
                }
            } else {
                // Decryption is fired by onLoad and postFlush events.
                $decryptedValue = $this->decryptValue($value);
                $propertyAccessor->setValue($entity, $decryptedValue);

                // Tell Doctrine the original value was the decrypted one.
                $unitOfWork->setOriginalEntityProperty(
                    $oid,
                    $key,
                    $decryptedValue
                );
            }
        }

        return !empty($properties);
    }

    public function postUpdate(PostUpdateEventArgs $args): void {
        $entity = $args->getObject();
        $em = $args->getObjectManager();

        $oid = spl_object_id($entity);
        if (isset($this->rawValues[$oid])) {
            $className = get_class($entity);
            $meta = $em->getClassMetadata($className);
            foreach ($this->rawValues[$oid] as $prop => $rawValue) {
                $refProperty = $meta->getPropertyAccessor($prop);
                $refProperty->setValue($entity, $rawValue);
            }

            unset($this->rawValues[$oid]);
        }
    }

/**
      * @return array<string, \Doctrine\ORM\Mapping\PropertyAccessor>
      */
    protected function getEncryptedFields(
        object $entity,
        EntityManagerInterface $em
    ): array {
        $className = get_class($entity);

        if (isset($this->encryptedFieldCache[$className])) {
            return $this->encryptedFieldCache[$className];
        }

        $meta = $em->getClassMetadata($className);

        $encryptedFields = [];

        foreach ($meta->getPropertyAccessors() as $key => $propertyAccessor) {
            if ($this->isEncryptedField($meta, $key)) {
                $encryptedFields[$key] = $propertyAccessor;
            }
        }

        $this->encryptedFieldCache[$className] = $encryptedFields;

        return $encryptedFields;
    }

    /**
      * Check if a field is encrypted by checking its attributes.
      */
    private function isEncryptedField(object $meta, string $fieldName): bool {
        $reflectionClass = $meta->getReflectionClass();
        if (!$reflectionClass->hasProperty($fieldName)) {
            return false;
        }

        $refProperty = $reflectionClass->getProperty($fieldName);

        if (method_exists($refProperty, 'getAttributes')) {
            foreach ($refProperty->getAttributes() as $refAttribute) {
                if (in_array($refAttribute->getName(), $this->encryptedAttributes)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isEncryptedProperty(
            ReflectionProperty $refProperty
    ): bool {
        // If PHP8, and has attributes.
        if (method_exists($refProperty, 'getAttributes')) {
            foreach ($refProperty->getAttributes() as $refAttribute) {
                if (in_array($refAttribute->getName(), $this->encryptedAttributes)) {
                    return true;
                }
            }
        }

        return false;
    }
}
