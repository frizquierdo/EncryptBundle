<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\BlindIndex;

/**
 * Trims and lower-cases (UTF-8) the value. Used by BlindIndex::LOWER_TRIM.
 */
final class LowerTrimNormalizer implements BlindIndexNormalizerInterface
{
    public function normalize(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
