<?php

namespace PSolutions\EncryptBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use PSolutions\EncryptBundle\Encryptors\EncryptorInterface;
use PSolutions\EncryptBundle\Exception\EncryptException;
use ReflectionAttribute;
use ReflectionClass;
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
	 * Caches the list of encrypted property names for each entity class.
	 * Keyed by fully qualified class name, value is array of property name strings.
	 */
	protected array $encryptedFieldCache = [];
	private array $rawValues = [];
	private bool $isDisabled;

	public function __construct(
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
	 * If the value is an object, or if it does not contain the suffix <ENC> then return the value itself back.
	 * Otherwise, decrypt the value and return.
	 */
	public function decryptValue(?string $value): ?string {
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
		$properties = $this->getEncryptedFields($entity, $em);

		// If no encrypted properties, return false.
		if (empty($properties)) {
			return false;
		}

		$unitOfWork = $em->getUnitOfWork();
		$oid = spl_object_id($entity);
		$className = get_class($entity);
		$meta = $em->getClassMetadata($className);
		$reflectionClass = $meta->getReflectionClass();

		foreach ($properties as $propName) {
			$refProperty = $reflectionClass->getProperty($propName);

			// Get the value in the entity.
			// As of PHP 8.1, getValue/setValue ignore visibility, so setAccessible is not needed.
			$value = $refProperty->getValue($entity);

			// Skip any null values.
			if (null === $value) {
				continue;
			}

			if (is_object($value)) {
				throw new EncryptException(
					sprintf('Cannot encrypt an object at %s:%s',
						$className,
						$propName
					),
					$value
				);
			}

			// Encryption is fired by onFlush event, else it is an onLoad event.
			if ($isEncryptOperation) {
				$changeSet = $unitOfWork->getEntityChangeSet($entity);

				// Encrypt value only if change has been detected by Doctrine (comparing unencrypted values, see postLoad flow)
				if (isset($changeSet[$propName])) {
					$encryptedValue = $this->encryptor->encrypt($value);
					$refProperty->setValue($entity, $encryptedValue);
					$unitOfWork->recomputeSingleEntityChangeSet(
						$em->getClassMetadata($className),
						$entity
					);

					if ($isInsert) {
						// Restore the decrypted value after the change set update
						$refProperty->setValue($entity, $value);
					} else {
						// Will be restored during postUpdate cycle
						$this->rawValues[$oid][$propName] = $value;
					}
				}
			} else {
				// Decryption is fired by onLoad and postFlush events.
				$decryptedValue = $this->decryptValue($value);
				$refProperty->setValue($entity, $decryptedValue);

				// Tell Doctrine the original value was the decrypted one.
				$unitOfWork->setOriginalEntityProperty(
					$oid,
					$propName,
					$decryptedValue
				);
			}
		}

		return true;
	}

	public function postUpdate(PostUpdateEventArgs $args): void {
		$entity = $args->getObject();
		$em = $args->getObjectManager();

		$oid = spl_object_id($entity);
		if (isset($this->rawValues[$oid])) {
			$className = get_class($entity);
			$meta = $em->getClassMetadata($className);
			$reflectionClass = $meta->getReflectionClass();
			foreach ($this->rawValues[$oid] as $prop => $rawValue) {
				$refProperty = $reflectionClass->getProperty($prop);
				$refProperty->setValue($entity, $rawValue);
			}

			unset($this->rawValues[$oid]);
		}
	}

	/**
	 * Get the list of encrypted property names for the given entity.
	 * Uses caching to avoid repeated reflection lookups.
	 *
	 * Compatible with both Doctrine 2 and Doctrine 3.
	 * Does NOT use ClassMetadata::getPropertyAccessors() which only exists in Doctrine 3.
	 *
	 * @return array<int, string> List of property names
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
		$reflectionClass = $meta->getReflectionClass();
		$encryptedFields = [];

		// getFieldNames() is available in both Doctrine 2 and 3.
		// It returns mapped field names (column fields), excluding associations.
		foreach ($meta->getFieldNames() as $fieldName) {
			if ($this->isEncryptedField($reflectionClass, $fieldName)) {
				$encryptedFields[] = $fieldName;
			}
		}

		$this->encryptedFieldCache[$className] = $encryptedFields;

		return $encryptedFields;
	}

	/**
	 * Check if a field is encrypted by checking its attributes on the ReflectionClass.
	 *
	 * Uses ReflectionClass::getProperty() which is native PHP and available in all
	 * PHP versions supporting attributes (8.0+). Does not depend on any Doctrine-specific
	 * method like getPropertyAccessors().
	 */
	private function isEncryptedField(ReflectionClass $reflectionClass, string $fieldName): bool {
		if (!$reflectionClass->hasProperty($fieldName)) {
			return false;
		}

		$refProperty = $reflectionClass->getProperty($fieldName);

		foreach ($this->encryptedAttributes as $attributeClass) {
			if (!empty($refProperty->getAttributes($attributeClass, ReflectionAttribute::IS_INSTANCEOF))) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a property has an encryption attribute.
	 */
	private function isEncryptedProperty(
		ReflectionProperty $refProperty
	): bool {
		foreach ($this->encryptedAttributes as $attributeClass) {
			if (!empty($refProperty->getAttributes($attributeClass, ReflectionAttribute::IS_INSTANCEOF))) {
				return true;
			}
		}

		return false;
	}
}
