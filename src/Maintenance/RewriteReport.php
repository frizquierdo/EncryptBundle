<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Maintenance;

/**
 * Outcome of a DatabaseRewriter run.
 */
final class RewriteReport
{
    public int $rowsScanned = 0;
    public int $rowsChanged = 0;

    /** @var array<string, int> rows changed per entity class */
    public array $changedByEntity = [];

    /**
     * Rows that share the same blind index on a unique column.
     *
     * @var list<array{entity: string, property: string, ids: list<string>}>
     */
    public array $collisions = [];

    /** @var list<string> */
    public array $errors = [];

    /** True once the changes were committed (never with dry-run). */
    public bool $committed = false;

    public bool $dryRun = false;

    public function isSuccessful(): bool
    {
        return empty($this->collisions) && empty($this->errors);
    }
}
