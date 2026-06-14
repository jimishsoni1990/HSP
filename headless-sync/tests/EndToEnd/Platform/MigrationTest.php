<?php

namespace HSP\Tests\EndToEnd\Platform;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

/**
 * Tests that verify the content schema tables have the correct column structure
 * after migrations have been applied.
 *
 * These tests ensure that the Content module's activate() migration produced
 * the expected table definitions in the PostgreSQL content schema.
 */
class MigrationTest extends BaseEndToEndTestCase
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
     * Verify content.posts table has the expected column structure.
     *
     * Expected columns: id (UUID PK), source_post_id, source_entity_type, slug,
     * title, excerpt, content, status, created_at, updated_at, deleted_at.
     */
    public function testContentPostsTableStructure(): void
    {
        $pdo = $this->getPostgresPdo();
        $columns = $this->getTableColumns($pdo, 'content', 'posts');

        $this->assertNotEmpty($columns, "Table 'content.posts' does not exist or has no columns.");

        $expectedColumns = [
            'id'                 => 'uuid',
            'source_post_id'     => 'character varying',
            'source_entity_type' => 'character varying',
            'slug'               => 'character varying',
            'title'              => 'text',
            'excerpt'            => 'text',
            'content'            => 'text',
            'status'             => 'character varying',
            'created_at'         => 'timestamp with time zone',
            'updated_at'         => 'timestamp with time zone',
            'deleted_at'         => 'timestamp with time zone',
        ];

        foreach ($expectedColumns as $col => $expectedType) {
            $this->assertArrayHasKey($col, $columns, "Column '{$col}' is missing from content.posts.");
            $this->assertEquals(
                $expectedType,
                $columns[$col],
                "Column '{$col}' in content.posts has type '{$columns[$col]}', expected '{$expectedType}'."
            );
        }
    }

    /**
     * Verify content.pages table has the expected column structure.
     *
     * Expected columns: id (UUID PK), source_post_id, source_entity_type, slug,
     * title, status, created_at, updated_at, deleted_at.
     *
     * Note: pages table does NOT have excerpt or content columns (unlike posts).
     */
    public function testContentPagesTableStructure(): void
    {
        $pdo = $this->getPostgresPdo();
        $columns = $this->getTableColumns($pdo, 'content', 'pages');

        $this->assertNotEmpty($columns, "Table 'content.pages' does not exist or has no columns.");

        $expectedColumns = [
            'id'                 => 'uuid',
            'source_post_id'     => 'character varying',
            'source_entity_type' => 'character varying',
            'slug'               => 'character varying',
            'title'              => 'text',
            'status'             => 'character varying',
            'created_at'         => 'timestamp with time zone',
            'updated_at'         => 'timestamp with time zone',
            'deleted_at'         => 'timestamp with time zone',
        ];

        foreach ($expectedColumns as $col => $expectedType) {
            $this->assertArrayHasKey($col, $columns, "Column '{$col}' is missing from content.pages.");
            $this->assertEquals(
                $expectedType,
                $columns[$col],
                "Column '{$col}' in content.pages has type '{$columns[$col]}', expected '{$expectedType}'."
            );
        }

        // Pages should NOT have excerpt or content columns
        $this->assertArrayNotHasKey('excerpt', $columns, "content.pages should not have an 'excerpt' column.");
        $this->assertArrayNotHasKey('content', $columns, "content.pages should not have a 'content' column.");
    }

    /**
     * Verify content.taxonomies table has the expected column structure.
     *
     * Expected columns: id (UUID PK), source_term_id, taxonomy_type, slug,
     * name, description, created_at, updated_at, deleted_at.
     */
    public function testContentTaxonomiesTableStructure(): void
    {
        $pdo = $this->getPostgresPdo();
        $columns = $this->getTableColumns($pdo, 'content', 'taxonomies');

        $this->assertNotEmpty($columns, "Table 'content.taxonomies' does not exist or has no columns.");

        $expectedColumns = [
            'id'            => 'uuid',
            'source_term_id' => 'character varying',
            'taxonomy_type' => 'character varying',
            'slug'          => 'character varying',
            'name'          => 'character varying',
            'description'   => 'text',
            'created_at'    => 'timestamp with time zone',
            'updated_at'    => 'timestamp with time zone',
            'deleted_at'    => 'timestamp with time zone',
        ];

        foreach ($expectedColumns as $col => $expectedType) {
            $this->assertArrayHasKey($col, $columns, "Column '{$col}' is missing from content.taxonomies.");
            $this->assertEquals(
                $expectedType,
                $columns[$col],
                "Column '{$col}' in content.taxonomies has type '{$columns[$col]}', expected '{$expectedType}'."
            );
        }
    }

    /**
     * Verify content.entity_taxonomies table has the expected column structure
     * and foreign key constraints.
     *
     * Expected columns: entity_id (UUID FK → content.posts), taxonomy_id (UUID FK → content.taxonomies).
     * Composite primary key on (entity_id, taxonomy_id).
     */
    public function testContentEntityTaxonomiesTableStructure(): void
    {
        $pdo = $this->getPostgresPdo();
        $columns = $this->getTableColumns($pdo, 'content', 'entity_taxonomies');

        $this->assertNotEmpty($columns, "Table 'content.entity_taxonomies' does not exist or has no columns.");

        // Verify columns
        $expectedColumns = [
            'entity_id'   => 'uuid',
            'taxonomy_id' => 'uuid',
        ];

        foreach ($expectedColumns as $col => $expectedType) {
            $this->assertArrayHasKey($col, $columns, "Column '{$col}' is missing from content.entity_taxonomies.");
            $this->assertEquals(
                $expectedType,
                $columns[$col],
                "Column '{$col}' in content.entity_taxonomies has type '{$columns[$col]}', expected '{$expectedType}'."
            );
        }

        // Verify foreign key constraints exist
        $fks = $this->getForeignKeys($pdo, 'content', 'entity_taxonomies');

        $this->assertNotEmpty($fks, 'content.entity_taxonomies should have foreign key constraints.');

        // Build a lookup of column_name => foreign_table_name for easy assertion
        $fkMap = [];
        foreach ($fks as $fk) {
            $fkMap[$fk['column_name']] = $fk['foreign_table_name'];
        }

        $this->assertArrayHasKey('entity_id', $fkMap, "FK on 'entity_id' is missing.");
        $this->assertEquals('posts', $fkMap['entity_id'], "FK 'entity_id' should reference 'content.posts'.");

        $this->assertArrayHasKey('taxonomy_id', $fkMap, "FK on 'taxonomy_id' is missing.");
        $this->assertEquals(
            'taxonomies',
            $fkMap['taxonomy_id'],
            "FK 'taxonomy_id' should reference 'content.taxonomies'."
        );
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

    /**
     * Helper: retrieve foreign key constraints for a given schema.table.
     *
     * @param \PDO   $pdo
     * @param string $schema
     * @param string $table
     * @return array<int, array{column_name: string, foreign_table_schema: string, foreign_table_name: string, foreign_column_name: string}>
     */
    private function getForeignKeys(\PDO $pdo, string $schema, string $table): array
    {
        $sql = "
            SELECT
                kcu.column_name,
                ccu.table_schema AS foreign_table_schema,
                ccu.table_name   AS foreign_table_name,
                ccu.column_name  AS foreign_column_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage AS ccu
                ON ccu.constraint_name = tc.constraint_name
                AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = :schema
              AND tc.table_name = :table
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['schema' => $schema, 'table' => $table]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
