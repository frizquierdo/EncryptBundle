<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Maintenance;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PSolutions\EncryptBundle\BlindIndex\BlindIndexer;
use PSolutions\EncryptBundle\Encryptors\EncryptorInterface;
use PSolutions\EncryptBundle\Exception\EncryptException;
use Throwable;

/**
 * Rewrites encrypted columns and blind indexes directly through DBAL (no entity lifecycle).
 *
 * Every run is all-or-nothing:
 *  1. Scan (read only): decrypt with the current encryptor, compute the new indexes and detect collisions on
 *     unique index columns. Any error or collision stops here, before writing anything.
 *  2. Write, inside one transaction per entity manager: re-encrypt and/or write indexes.
 *  3. Verify, inside the same transactions: everything written decrypts with the target encryptor to the same
 *     plain value and every index matches. Then commit all (or roll back all on failure or dry-run).
 */
class DatabaseRewriter
{
    /** Encrypted columns are left as they are. */
    public const DATA_KEEP = 'keep';
    /** Encrypted columns are decrypted with the source encryptor and encrypted with the target one. */
    public const DATA_REENCRYPT = 'reencrypt';

    /** Blind indexes are left as they are. */
    public const INDEX_SKIP = 'skip';
    /** Blind indexes are recomputed with the given key. */
    public const INDEX_REBUILD = 'rebuild';
    /** Blind indexes are set to null. */
    public const INDEX_CLEAR = 'clear';

    public function __construct(private readonly BlindIndexer $blindIndexer)
    {
    }

    /**
     * @param array<string, EntityManagerInterface> $managers
     * @param string|null                           $blindIndexKey binary key for INDEX_REBUILD (default: current key)
     * @param string|null                           $entityFilter  only this entity class (and its subclasses)
     * @param callable|null                         $afterWrite    test hook, called after each written row
     */
    public function rewrite(
        array $managers,
        EncryptorInterface $sourceEncryptor,
        ?EncryptorInterface $targetEncryptor = null,
        string $dataMode = self::DATA_KEEP,
        string $indexMode = self::INDEX_REBUILD,
        ?string $blindIndexKey = null,
        ?string $entityFilter = null,
        int $batchSize = 200,
        bool $dryRun = false,
        ?callable $afterWrite = null,
    ): RewriteReport {
        if (self::DATA_REENCRYPT === $dataMode && null === $targetEncryptor) {
            throw new \InvalidArgumentException('A target encryptor is required to re-encrypt.');
        }
        if (self::INDEX_REBUILD === $indexMode) {
            $blindIndexKey ??= $this->blindIndexer->getKey();
        }

        $targetEncryptor ??= $sourceEncryptor;
        $batchSize = max(1, $batchSize);
        $report = new RewriteReport();
        $report->dryRun = $dryRun;

        $job = new class() {
            public string $dataMode;
            public string $indexMode;
            public ?string $key;
            public EncryptorInterface $source;
            public EncryptorInterface $target;
        };
        $job->dataMode = $dataMode;
        $job->indexMode = $indexMode;
        $job->key = $blindIndexKey;
        $job->source = $sourceEncryptor;
        $job->target = $targetEncryptor;

        /** @var array<string, list<array>> $plans */
        $plans = [];
        foreach ($managers as $name => $em) {
            $plans[$name] = $this->buildPlans($em, $entityFilter, $dataMode, $indexMode);
        }

        // Phase 1: read-only scan.
        foreach ($managers as $name => $em) {
            foreach ($plans[$name] as $plan) {
                $this->scan($em->getConnection(), $plan, $job, $batchSize, $report);
            }
        }

        if (!$report->isSuccessful() || 0 === $report->rowsChanged) {
            return $report;
        }

        // Phase 2 + 3: write and verify, all managers in their own transaction, committed together.
        /** @var Connection[] $connections */
        $connections = [];
        try {
            foreach ($managers as $name => $em) {
                $connection = $em->getConnection();
                $connection->beginTransaction();
                $connections[] = $connection;
            }

            foreach ($managers as $name => $em) {
                foreach ($plans[$name] as $plan) {
                    $fingerprints = $this->write($em->getConnection(), $plan, $job, $batchSize, $afterWrite);
                    $this->verify($em->getConnection(), $plan, $job, $batchSize, $fingerprints, $report);
                }
            }

            if (!$report->isSuccessful() || $dryRun) {
                $this->rollBack($connections);

                return $report;
            }

            foreach ($connections as $connection) {
                $connection->commit();
            }
            $report->committed = true;
        } catch (Throwable $e) {
            $this->rollBack($connections);
            $report->errors[] = sprintf('Rolled back, the database was not modified: %s', $e->getMessage());
        }

        return $report;
    }

    /**
     * Tables touched by a run, merged per table (single table inheritance shares one table).
     *
     * @return list<array{entity: string, table: string, ids: list<string>, encrypted: list<string>,
     *     indexes: array<string, array{property: string, source: string, normalizer: string, unique: bool}>}>
     */
    public function buildPlans(
        EntityManagerInterface $em,
        ?string $entityFilter = null,
        string $dataMode = self::DATA_KEEP,
        string $indexMode = self::INDEX_REBUILD,
    ): array {
        $platform = $em->getConnection()->getDatabasePlatform();
        $quoter = $em->getConfiguration()->getQuoteStrategy();
        $plans = [];

        /** @var ClassMetadata $meta */
        foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->isMappedSuperclass || $meta->isEmbeddedClass) {
                continue;
            }
            if (null !== $entityFilter && !is_a($meta->getName(), ltrim($entityFilter, '\\'), true)) {
                continue;
            }

            $ownField = static fn (string $field): bool => !($meta->isInheritanceTypeJoined() && $meta->isInheritedField($field));
            $column = static fn (string $field): string => $quoter->getColumnName($field, $meta, $platform);

            $encrypted = [];
            if (self::DATA_REENCRYPT === $dataMode) {
                foreach ($meta->getFieldNames() as $field) {
                    if ($ownField($field) && $this->blindIndexer->isEncryptedField($meta, $field)) {
                        $encrypted[] = $column($field);
                    }
                }
            }

            $indexes = [];
            if (self::INDEX_SKIP !== $indexMode) {
                foreach ($this->blindIndexer->getBlindIndexes($meta) as $property => $blindIndex) {
                    if (!$ownField($property)) {
                        continue;
                    }
                    if (!$ownField($blindIndex->source)) {
                        throw new EncryptException(sprintf(
                            'The blind index %s::$%s and its source "%s" must be in the same table.',
                            $meta->getName(),
                            $property,
                            $blindIndex->source
                        ));
                    }

                    $indexes[$column($property)] = [
                        'property' => $property,
                        'source' => $column($blindIndex->source),
                        'normalizer' => $blindIndex->normalizer,
                        'unique' => $this->isUnique($meta, $property),
                    ];
                }
            }

            if (empty($encrypted) && empty($indexes)) {
                continue;
            }

            $table = $quoter->getTableName($meta, $platform);
            if (isset($plans[$table])) {
                $plans[$table]['encrypted'] = array_values(array_unique([...$plans[$table]['encrypted'], ...$encrypted]));
                $plans[$table]['indexes'] += $indexes;
                continue;
            }

            $plans[$table] = [
                'entity' => $meta->rootEntityName ?: $meta->getName(),
                'table' => $table,
                'ids' => $quoter->getIdentifierColumnNames($meta, $platform),
                'encrypted' => $encrypted,
                'indexes' => $indexes,
            ];
        }

        return array_values($plans);
    }

    private function scan(Connection $connection, array $plan, object $job, int $batchSize, RewriteReport $report): void
    {
        /** @var array<string, array<string, list<string>>> $seen column => hash => ids */
        $seen = [];

        foreach ($this->rows($connection, $plan, $batchSize) as $row) {
            ++$report->rowsScanned;
            $id = $this->idString($plan, $row);

            try {
                [$changes] = $this->transform($plan, $row, $job, false);
            } catch (Throwable $e) {
                $report->errors[] = sprintf('%s #%s: %s', $plan['entity'], $id, $e->getMessage());
                continue;
            }

            if (!empty($changes)) {
                ++$report->rowsChanged;
                $report->changedByEntity[$plan['entity']] = ($report->changedByEntity[$plan['entity']] ?? 0) + 1;
            }

            foreach ($plan['indexes'] as $column => $index) {
                if (!$index['unique']) {
                    continue;
                }
                $hash = array_key_exists($column, $changes) ? $changes[$column] : $row[$column];
                if (null !== $hash) {
                    $seen[$column][$hash][] = $id;
                }
            }
        }

        foreach ($seen as $column => $hashes) {
            foreach ($hashes as $ids) {
                if (count($ids) > 1) {
                    $report->collisions[] = [
                        'entity' => $plan['entity'],
                        'property' => $plan['indexes'][$column]['property'],
                        'ids' => $ids,
                    ];
                }
            }
        }
    }

    /**
     * @return array<string, string> plain-value fingerprint per row id, to verify after writing
     */
    private function write(Connection $connection, array $plan, object $job, int $batchSize, ?callable $afterWrite): array
    {
        $fingerprints = [];

        foreach ($this->rows($connection, $plan, $batchSize) as $row) {
            [$changes, $plain] = $this->transform($plan, $row, $job, true);
            $fingerprints[$this->idString($plan, $row)] = $this->fingerprint($plain);

            if (empty($changes)) {
                continue;
            }

            $criteria = [];
            foreach ($plan['ids'] as $idColumn) {
                $criteria[$idColumn] = $row[$this->key($idColumn)];
            }
            $connection->update($plan['table'], $changes, $criteria);

            if (null !== $afterWrite) {
                $afterWrite($plan['entity'], $criteria);
            }
        }

        return $fingerprints;
    }

    private function verify(
        Connection $connection,
        array $plan,
        object $job,
        int $batchSize,
        array $fingerprints,
        RewriteReport $report,
    ): void {
        $verifyJob = clone $job;
        $verifyJob->source = $job->target;
        $verifyJob->dataMode = self::DATA_KEEP;
        $seen = [];

        foreach ($this->rows($connection, $plan, $batchSize) as $row) {
            $id = $this->idString($plan, $row);
            try {
                [$changes, $plain] = $this->transform($plan, $row, $verifyJob, false);
            } catch (Throwable $e) {
                $report->errors[] = sprintf('Verification of %s #%s failed: %s', $plan['entity'], $id, $e->getMessage());
                continue;
            }

            if (!empty($changes) || ($fingerprints[$id] ?? null) !== $this->fingerprint($plain)) {
                $report->errors[] = sprintf('Verification of %s #%s failed: written values do not match.', $plan['entity'], $id);
            }

            foreach ($plan['indexes'] as $column => $index) {
                if ($index['unique'] && null !== $row[$column] && isset($seen[$column][$row[$column]])) {
                    $report->errors[] = sprintf('Verification of %s #%s failed: duplicated %s.', $plan['entity'], $id, $index['property']);
                }
                $seen[$column][(string) $row[$column]] = true;
            }
        }
    }

    /**
     * Computes the new column values of a row.
     *
     * @return array{0: array<string, ?string>, 1: array<string, ?string>} [changed columns => value, plain values]
     */
    private function transform(array $plan, array $row, object $job, bool $materialize): array
    {
        $changes = [];
        $plain = [];

        $decrypt = function (string $column) use ($row, $job, &$plain): ?string {
            if (!array_key_exists($column, $plain)) {
                $value = $row[$this->key($column)];
                $plain[$column] = null === $value ? null : $job->source->decrypt((string) $value);
            }

            return $plain[$column];
        };

        foreach ($plan['encrypted'] as $column) {
            $value = $decrypt($column);
            if (null !== $value && self::DATA_REENCRYPT === $job->dataMode) {
                // In the scan phase the new ciphertext is not needed: it always differs (random IV).
                $changes[$column] = $materialize ? $job->target->encrypt($value) : '';
            }
        }

        foreach ($plan['indexes'] as $column => $index) {
            $current = $row[$this->key($column)];
            $current = null === $current ? null : (string) $current;

            if (self::INDEX_CLEAR === $job->indexMode) {
                $hash = null;
            } else {
                $hash = $this->blindIndexer->compute($decrypt($index['source']), $index['normalizer'], $job->key);
            }

            if ($hash !== $current) {
                $changes[$column] = $hash;
            }
        }

        return [$changes, $plain];
    }

    /**
     * Iterates the rows of a plan in batches, ordered by identifier.
     *
     * @return \Generator<array<string, mixed>>
     */
    private function rows(Connection $connection, array $plan, int $batchSize): \Generator
    {
        $columns = array_values(array_unique([
            ...$plan['ids'],
            ...$plan['encrypted'],
            ...array_keys($plan['indexes']),
            ...array_column($plan['indexes'], 'source'),
        ]));

        $sql = sprintf(
            'SELECT %s FROM %s ORDER BY %s',
            implode(', ', $columns),
            $plan['table'],
            implode(', ', $plan['ids'])
        );
        $platform = $connection->getDatabasePlatform();

        for ($offset = 0; ; $offset += $batchSize) {
            $rows = $connection->fetchAllAssociative($platform->modifyLimitQuery($sql, $batchSize, $offset));

            foreach ($rows as $row) {
                // Normalize keys to the unquoted column names used everywhere else.
                $normalized = [];
                foreach ($row as $key => $value) {
                    $normalized[strtolower((string) $key)] = $value;
                }
                // Indexed both by the (possibly quoted) column used in SQL and by its unquoted name.
                $mapped = [];
                foreach ($columns as $column) {
                    $value = $normalized[strtolower($this->key($column))] ?? null;
                    $mapped[$column] = $value;
                    $mapped[$this->key($column)] = $value;
                }

                yield $mapped;
            }

            if (count($rows) < $batchSize) {
                return;
            }
        }
    }

    private function isUnique(ClassMetadata $meta, string $field): bool
    {
        $mapping = $meta->getFieldMapping($field);
        // ORM 2: array; ORM 3+: FieldMapping object (ArrayAccess is deprecated).
        if (is_array($mapping) ? !empty($mapping['unique']) : !empty($mapping->unique)) {
            return true;
        }

        $column = $meta->getColumnName($field);
        foreach (['uniqueConstraints', 'indexes'] as $type) {
            foreach ($meta->table[$type] ?? [] as $constraint) {
                if ('indexes' === $type && !in_array('unique', $constraint['flags'] ?? [], true)) {
                    continue;
                }
                $columns = $constraint['columns'] ?? array_map($meta->getColumnName(...), $constraint['fields'] ?? []);
                if ([$column] === array_values($columns)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function idString(array $plan, array $row): string
    {
        return implode('-', array_map(fn (string $column) => (string) $row[$this->key($column)], $plan['ids']));
    }

    private function fingerprint(array $plain): string
    {
        ksort($plain);

        return hash('sha256', serialize($plain));
    }

    /** Unquoted column name. */
    private function key(string $column): string
    {
        return trim($column, '`"[]');
    }

    /** @param Connection[] $connections */
    private function rollBack(array $connections): void
    {
        foreach ($connections as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
