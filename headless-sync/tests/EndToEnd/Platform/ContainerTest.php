<?php

namespace HSP\Tests\EndToEnd\Platform;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

/**
 * Tests that verify the PostgreSQL container has the expected schemas and system tables.
 *
 * These tests validate the foundational database infrastructure required by the
 * Headless Sync Platform, including schemas, event store, queue, and dead letter tables.
 */
class ContainerTest extends BaseEndToEndTestCase
{
    /**
     * Skip all tests if the PostgreSQL container is not running.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->getPostgresPdo() === null) {
            $this->markTestSkipped(
                'PostgreSQL connection is not available: ' . ($this->pgConnectionError ?? 'Unknown error')
            );
        }
    }

    /**
     * Verify that the 'system' schema exists in PostgreSQL.
     *
     * The system schema holds all infrastructure tables: events, queue_jobs,
     * dead_letter_jobs, aggregate_versions, worker_heartbeats, etc.
     */
    public function testSystemSchemaExists(): void
    {
        $pdo = $this->getPostgresPdo();

        $stmt = $pdo->prepare(
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name = :schema"
        );
        $stmt->execute(['schema' => 'system']);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($result, "Schema 'system' does not exist in PostgreSQL.");
        $this->assertEquals('system', $result['schema_name']);
    }

    /**
     * Verify that the 'content' schema exists in PostgreSQL.
     *
     * The content schema holds projection tables: posts, pages, taxonomies,
     * entity_taxonomies, and media.
     */
    public function testContentSchemaExists(): void
    {
        $pdo = $this->getPostgresPdo();

        $stmt = $pdo->prepare(
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name = :schema"
        );
        $stmt->execute(['schema' => 'content']);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($result, "Schema 'content' does not exist in PostgreSQL.");
        $this->assertEquals('content', $result['schema_name']);
    }

    /**
     * Verify that system.events table exists and has the correct columns.
     *
     * Expected columns: id (UUID PK), event_type, event_version, aggregate_type,
     * aggregate_id, aggregate_version, source_updated_at, created_at, payload (JSONB).
     */
    public function testSystemEventsTableExists(): void
    {
        $pdo = $this->getPostgresPdo();
        $columns = $this->getTableColumns($pdo, 'system', 'events');

        $this->assertNotEmpty($columns, "Table 'system.events' does not exist or has no columns.");

        $expectedColumns = [
            'id',
            'event_type',
            'event_version',
            'aggregate_type',
            'aggregate_id',
            'aggregate_version',
            'source_updated_at',
            'created_at',
            'payload',
        ];

        foreach ($expectedColumns as $col) {
            $this->assertArrayHasKey($col, $columns, "Column '{$col}' is missing from system.events.");
        }

        // Verify key data types
        $this->assertStringContainsString('uuid', strtolower($columns['id']));
        $this->assertStringContainsString('jsonb', strtolower($columns['payload']));
    }

    /**
     * Verify that system.queue_jobs table exists and has the correct columns.
     *
     * Expected columns: job_id (BIGSERIAL PK), queue_name, event_id (UUID),
     * payload (JSONB), status, attempts, available_at, reserved_at, created_at, updated_at.
     */
    public function testSystemQueueJobsTableExists(): void
    {
        $pdo = $this->getPostgresPdo();
        $columns = $this->getTableColumns($pdo, 'system', 'queue_jobs');

        $this->assertNotEmpty($columns, "Table 'system.queue_jobs' does not exist or has no columns.");

        $expectedColumns = [
            'job_id',
            'queue_name',
            'event_id',
            'payload',
            'status',
            'attempts',
            'available_at',
            'reserved_at',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedColumns as $col) {
            $this->assertArrayHasKey($col, $columns, "Column '{$col}' is missing from system.queue_jobs.");
        }

        // Verify key data types
        $this->assertStringContainsString('uuid', strtolower($columns['event_id']));
        $this->assertStringContainsString('jsonb', strtolower($columns['payload']));
    }

    /**
     * Verify that system.dead_letter_jobs table exists.
     *
     * Expected columns: job_id (BIGINT PK), queue_name, event_id (UUID),
     * payload (JSONB), failed_at, exception_message.
     */
    public function testSystemDeadLetterJobsTableExists(): void
    {
        $pdo = $this->getPostgresPdo();
        $columns = $this->getTableColumns($pdo, 'system', 'dead_letter_jobs');

        $this->assertNotEmpty($columns, "Table 'system.dead_letter_jobs' does not exist or has no columns.");

        $expectedColumns = [
            'job_id',
            'queue_name',
            'event_id',
            'payload',
            'failed_at',
            'exception_message',
        ];

        foreach ($expectedColumns as $col) {
            $this->assertArrayHasKey($col, $columns, "Column '{$col}' is missing from system.dead_letter_jobs.");
        }
    }

    /**
     * Verify that system.aggregate_versions table exists.
     *
     * Expected columns: aggregate_type, aggregate_id, version, updated_at.
     * Composite primary key on (aggregate_type, aggregate_id).
     */
    public function testSystemAggregateVersionsTableExists(): void
    {
        $pdo = $this->getPostgresPdo();
        $columns = $this->getTableColumns($pdo, 'system', 'aggregate_versions');

        $this->assertNotEmpty($columns, "Table 'system.aggregate_versions' does not exist or has no columns.");

        $expectedColumns = [
            'aggregate_type',
            'aggregate_id',
            'version',
            'updated_at',
        ];

        foreach ($expectedColumns as $col) {
            $this->assertArrayHasKey(
                $col,
                $columns,
                "Column '{$col}' is missing from system.aggregate_versions."
            );
        }
    }

    /**
     * Helper: retrieve columns for a given schema.table as an associative array
     * of column_name => data_type.
     *
     * @param \PDO   $pdo
     * @param string $schema
     * @param string $table
     * @return array<string, string>
     */
    private function getTableColumns(\PDO $pdo, string $schema, string $table): array
    {
        $stmt = $pdo->prepare(
            "SELECT column_name, data_type
             FROM information_schema.columns
             WHERE table_schema = :schema AND table_name = :table
             ORDER BY ordinal_position"
        );
        $stmt->execute(['schema' => $schema, 'table' => $table]);

        $columns = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $columns[$row['column_name']] = $row['data_type'];
        }

        return $columns;
    }
}
