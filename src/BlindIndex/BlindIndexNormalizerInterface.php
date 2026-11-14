<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\BlindIndex;

/**
 * Normalizes a plain value before computing its blind index.
 *
 * Implementations are autoconfigured as services and referenced by FQCN in #[BlindIndex(normalizer: ...)].
 * Changing what a normalizer returns invalidates the existing indexes (run encrypt:blind-index:rebuild).
 */
interface BlindIndexNormalizerInterface
{
    public function normalize(string $value): string;
}
