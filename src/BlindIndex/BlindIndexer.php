<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\BlindIndex;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PSolutions\EncryptBundle\Attributes\BlindIndex;
use PSolutions\EncryptBundle\Attributes\Encrypted;
use PSolutions\EncryptBundle\Event\EncryptKeyEvent;
use PSolutions\EncryptBundle\Event\EncryptKeyEvents;
use PSolutions\EncryptBundle\Exception\EncryptException;
use Psr\Container\ContainerInterface;
use ReflectionAttribute;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Computes blind indexes: HMAC-SHA256(normalize(value), key), as 64 hex characters.
 *
 * The key is `blind_index_key` when configured; otherwise it is derived from `encrypt_key` with HKDF, so the
 * same key is never used both to encrypt and to hash.
 */
class BlindIndexer
{
    public const DERIVATION_INFO = 'psolutions-encrypt-bundle:blind-index';
    public const PLACEHOLDER_KEY = 'change_me!';

    /** @var array<string, array<string, BlindIndex>> */
    private array $metadataCache = [];

    /** @var array<string, string> derived keys, keyed by base64 encrypt key */
    private array $derivedKeys = [];

    /** @var array<string, BlindIndexNormalizerInterface> */
    private array $normalizerInstances = [];

    /**
     * @param string[] $encryptedAttributes attribute classes that mark encrypted properties
     */
    public function __construct(
        private readonly ?ManagerRegistry $registry,
        private readonly ?EventDispatcherInterface $dispatcher,
        private readonly ?string $encryptKey,
        private readonly ?string $blindIndexKey = null,
        private readonly array $encryptedAttributes = [Encrypted::class],
        private readonly ?ContainerInterface $normalizers = null,
    ) {
    }

    /**
     * Blind index of $value for the given index property, applying its declared normalizer.
     * Use it to query: $repo->findOneBy(['emailHash' => $indexer->hash(User::class, 'emailHash', $email)]).
     */
    public function hash(string $entityClass, string $property, ?string $value): ?string
    {
        $em = $this->registry?->getManagerForClass($entityClass);

        if (null === $em) {
            throw new EncryptException(sprintf('No Doctrine entity manager handles "%s".', $entityClass));
        }

        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata($entityClass);
        $indexes = $this->getBlindIndexes($meta);

        if (!isset($indexes[$property])) {
            throw new EncryptException(sprintf('%s::$%s has no #[BlindIndex] attribute.', $entityClass, $property));
        }

        return $this->compute($value, $indexes[$property]->normalizer);
    }

    /**
     * Blind index of an already plain value. $binaryKey overrides the configured key (used by key rotation).
     * Returns null for null or for a value that is empty after normalization.
     */
    public function compute(?string $value, string $normalizer = BlindIndex::NONE, ?string $binaryKey = null): ?string
    {
        if (null === $value) {
            return null;
        }

        $normalized = $this->normalize($value, $normalizer);
        if ('' === $normalized) {
            return null;
        }

        return hash_hmac('sha256', $normalized, $binaryKey ?? $this->getKey());
    }

    public function normalize(string $value, string $normalizer): string
    {
        return match ($normalizer) {
            BlindIndex::NONE => $value,
            BlindIndex::LOWER_TRIM => (new LowerTrimNormalizer())->normalize($value),
            default => $this->getNormalizer($normalizer)->normalize($value),
        };
    }

    /**
     * Blind index properties of an entity, keyed by property name. Cached per class.
     *
     * @return array<string, BlindIndex>
     *
     * @throws EncryptException when an index is also encrypted or its source is not a mapped field
     */
    public function getBlindIndexes(ClassMetadata $meta): array
    {
        $className = $meta->getName();
        if (isset($this->metadataCache[$className])) {
            return $this->metadataCache[$className];
        }

        $reflectionClass = $meta->getReflectionClass();
        $indexes = [];

        foreach ($meta->getFieldNames() as $fieldName) {
            if (!$reflectionClass->hasProperty($fieldName)) {
                continue;
            }

            $refProperty = $reflectionClass->getProperty($fieldName);
            $attributes = $refProperty->getAttributes(BlindIndex::class);

            if (empty($attributes)) {
                continue;
            }

            if ($this->isEncryptedField($meta, $fieldName)) {
                throw new EncryptException(sprintf(
                    '%s::$%s is a blind index and cannot be #[Encrypted]: it must stay deterministic.',
                    $className,
                    $fieldName
                ));
            }

            /** @var BlindIndex $attribute */
            $attribute = $attributes[0]->newInstance();

            if ($attribute->source === $fieldName || !$meta->hasField($attribute->source)) {
                throw new EncryptException(sprintf(
                    'The source "%s" of the blind index %s::$%s must be another mapped field of the same entity.',
                    $attribute->source,
                    $className,
                    $fieldName
                ));
            }

            $indexes[$fieldName] = $attribute;
        }

        return $this->metadataCache[$className] = $indexes;
    }

    /**
     * Whether a mapped field carries one of the configured encryption attributes.
     */
    public function isEncryptedField(ClassMetadata $meta, string $fieldName): bool
    {
        $reflectionClass = $meta->getReflectionClass();

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
     * Whether `blind_index_key` is configured (otherwise the key is derived from `encrypt_key`).
     */
    public function hasDedicatedKey(): bool
    {
        return null !== $this->blindIndexKey && '' !== $this->blindIndexKey;
    }

    /**
     * The binary key currently used to compute blind indexes.
     */
    public function getKey(): string
    {
        if ($this->hasDedicatedKey()) {
            return self::decodeKey($this->blindIndexKey, 'blind_index_key (PSOLUTIONS_BLIND_INDEX_KEY)');
        }

        // Follow the same runtime key override as the encryptor, so the derived key matches the effective one.
        $encryptKey = $this->encryptKey;

        if (null !== $this->dispatcher) {
            $event = new EncryptKeyEvent();
            $this->dispatcher->dispatch($event, EncryptKeyEvents::LOAD_KEY);

            if (null !== $event->getKey()) {
                $encryptKey = $event->getKey();
            }
        }

        $encryptKey = (string) $encryptKey;

        return $this->derivedKeys[$encryptKey] ??= self::deriveKey(
            self::decodeKey($encryptKey, 'encrypt_key (PSOLUTIONS_ENCRYPT_KEY)')
        );
    }

    /**
     * Derives the blind index key from the binary encryption key.
     */
    public static function deriveKey(string $binaryEncryptKey): string
    {
        return hash_hkdf('sha256', $binaryEncryptKey, 32, self::DERIVATION_INFO);
    }

    /**
     * Decodes and validates a base64 256-bit key.
     *
     * @throws EncryptException
     */
    public static function decodeKey(?string $base64Key, string $name = 'key'): string
    {
        if (null === $base64Key || '' === $base64Key || self::PLACEHOLDER_KEY === $base64Key) {
            throw new EncryptException(sprintf(
                'The %s is not set%s. Run "bin/console encrypt:install" to create it, or generate one with '
                . '"bin/console encrypt:genkey".',
                $name,
                self::PLACEHOLDER_KEY === $base64Key ? ' (it still has the placeholder value "change_me!")' : ''
            ));
        }

        $key = base64_decode($base64Key, true);

        if (false === $key || 32 !== strlen($key)) {
            throw new EncryptException(sprintf(
                'The %s must be a base64-encoded 256-bit key. Generate one with "bin/console encrypt:genkey".',
                $name
            ));
        }

        return $key;
    }

    private function getNormalizer(string $class): BlindIndexNormalizerInterface
    {
        if (isset($this->normalizerInstances[$class])) {
            return $this->normalizerInstances[$class];
        }

        $normalizer = null;

        if (null !== $this->normalizers && $this->normalizers->has($class)) {
            $normalizer = $this->normalizers->get($class);
        } elseif (class_exists($class) && is_subclass_of($class, BlindIndexNormalizerInterface::class)) {
            // Not registered as a service (e.g. outside the container): instantiate if it has no dependencies.
            $constructor = (new \ReflectionClass($class))->getConstructor();

            if (null === $constructor || 0 === $constructor->getNumberOfRequiredParameters()) {
                $normalizer = new $class();
            }
        }

        if (!$normalizer instanceof BlindIndexNormalizerInterface) {
            throw new EncryptException(sprintf(
                'Unknown blind index normalizer "%s": use BlindIndex::NONE, BlindIndex::LOWER_TRIM or a service '
                . 'implementing %s.',
                $class,
                BlindIndexNormalizerInterface::class
            ));
        }

        return $this->normalizerInstances[$class] = $normalizer;
    }
}
