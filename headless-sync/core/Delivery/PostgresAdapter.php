<?php

namespace HSP\Core\Delivery;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\CanonicalModelInterface;
use PDO;
use RuntimeException;

/**
 * PostgreSQL delivery adapter.
 *
 * Persists canonical models into the `content` schema of the PostgreSQL
 * projection database. Uses UPSERT (INSERT … ON CONFLICT) with an
 * aggregate-version fence to guarantee idempotent, ordered writes.
 */
class PostgresAdapter implements AdapterInterface
{
    /**
     * Map of aggregate types to their PostgreSQL target tables.
     *
     * @var array<string, string>
     */
    private const TABLE_MAP = [
        'post'     => 'content.posts',
        'page'     => 'content.pages',
        'category' => 'content.taxonomies',
    ];

    /**
     * Map of aggregate types to their primary-key column.
     *
     * @var array<string, string>
     */
    private const PK_MAP = [
        'post'     => 'id',
        'page'     => 'id',
        'category' => 'term_id',
    ];

    /**
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * PostgresAdapter constructor.
     *
     * @param PDO $pdo A PDO connection to the PostgreSQL projection database.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * {@inheritDoc}
     *
     * Performs an UPSERT with a version fence: the row is only written
     * when the incoming aggregate version is greater than the stored
     * version, preventing out-of-order replays from overwriting newer data.
     *
     * @throws RuntimeException If the aggregate type is not supported.
     */
    public function persist(CanonicalModelInterface $model): void
    {
        $table = $this->resolveTable($model->getAggregateType());
        $pk    = $this->resolvePrimaryKey($model->getAggregateType());
        $data  = $model->toArray();

        if (empty($data)) {
            return;
        }

        $columns      = array_keys($data);
        $placeholders = array_map(fn(string $col): string => ':' . $col, $columns);

        // Build SET clause for the ON CONFLICT UPDATE, excluding the PK.
        $updatePairs = [];
        foreach ($columns as $col) {
            if ($col === $pk) {
                continue;
            }
            $updatePairs[] = sprintf('%s = EXCLUDED.%s', $col, $col);
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s WHERE %s.aggregate_version < EXCLUDED.aggregate_version',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
            $pk,
            implode(', ', $updatePairs),
            $table
        );

        $stmt = $this->pdo->prepare($sql);

        foreach ($data as $column => $value) {
            $stmt->bindValue(':' . $column, $value, $this->pdoType($value));
        }

        $stmt->execute();
    }

    /**
     * {@inheritDoc}
     *
     * Hard-deletes the row identified by aggregate type and ID.
     *
     * @throws RuntimeException If the aggregate type is not supported.
     */
    public function delete(string $aggregateType, string $aggregateId): void
    {
        $table = $this->resolveTable($aggregateType);
        $pk    = $this->resolvePrimaryKey($aggregateType);

        $sql  = sprintf('DELETE FROM %s WHERE %s = :id', $table, $pk);
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $aggregateId, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Resolve the fully-qualified table name for an aggregate type.
     *
     * @param string $aggregateType
     * @return string
     * @throws RuntimeException If the aggregate type is unknown.
     */
    private function resolveTable(string $aggregateType): string
    {
        if (!isset(self::TABLE_MAP[$aggregateType])) {
            throw new RuntimeException(
                sprintf('Unsupported aggregate type "%s". Supported: %s', $aggregateType, implode(', ', array_keys(self::TABLE_MAP)))
            );
        }

        return self::TABLE_MAP[$aggregateType];
    }

    /**
     * Resolve the primary-key column for an aggregate type.
     *
     * @param string $aggregateType
     * @return string
     * @throws RuntimeException If the aggregate type is unknown.
     */
    private function resolvePrimaryKey(string $aggregateType): string
    {
        if (!isset(self::PK_MAP[$aggregateType])) {
            throw new RuntimeException(
                sprintf('No primary key mapping for aggregate type "%s".', $aggregateType)
            );
        }

        return self::PK_MAP[$aggregateType];
    }

    /**
     * Determine the appropriate PDO parameter type for a value.
     *
     * @param mixed $value
     * @return int PDO::PARAM_* constant.
     */
    private function pdoType(mixed $value): int
    {
        return match (true) {
            is_int($value)  => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        };
    }
}
