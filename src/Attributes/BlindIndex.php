<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Attributes;

use Attribute;

/**
 * Marks a property as the blind index of another property of the same entity.
 *
 * The property stores HMAC-SHA256(normalize(source value), key) as 64 hex characters, so it can be used in
 * equality queries and UNIQUE indexes while the source stays encrypted. Exact match only.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class BlindIndex
{
    /** The value is hashed as is. */
    public const NONE = 'none';

    /** The value is trimmed and lower-cased (UTF-8) before hashing. Typical for emails. */
    public const LOWER_TRIM = 'lower_trim';

    /**
     * @param string $source     Mapped property of the same entity holding the value (usually #[Encrypted])
     * @param string $normalizer self::NONE, self::LOWER_TRIM or the FQCN of a BlindIndexNormalizerInterface service
     */
    public function __construct(
        public readonly string $source,
        public readonly string $normalizer = self::NONE,
    ) {
    }
}
