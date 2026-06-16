<?php

namespace HSP\Tests\EndToEnd\Content;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;
use HSP\Bootstrap\Application;
use HSP\Core\Events\OutboxService;
use HSP\Modules\Commerce\Events\CommerceEventTypes;

class ProductSyncTest extends BaseEndToEndTestCase
{
    /**
     * @var Application
     */
    protected $app;

    /**
     * @var OutboxService
     */
    protected $outbox;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->getPostgresPdo() === null) {
            $this->markTestSkipped('PostgreSQL connection is not available. Error: ' . $this->pgConnectionError);
        }

        // Initialize HSP Application kernel for outbox generation
        $pluginRoot = dirname(__DIR__, 3); // j:/wp-postgresql/headless-sync
        $this->app = new Application($pluginRoot);
        
        // Mock config before boot to override DB host if necessary, but it uses the standard pgsql connection
        $this->app->boot();
        $this->outbox = $this->app->make(OutboxService::class);
    }

    /**
     * Helper to run commerce queue CLI worker.
     */
    private function runCommerceWorker(): void
    {
        $process = $this->runWpCli(['headless-sync', 'worker', 'run', '--queue=commerce']);
        $this->assertTrue($process->isSuccessful(), "Worker CLI run failed: " . $process->getErrorOutput());
    }

    /**
     * Test Case 1: Sync simple product creation and retrieval.
     */
    public function testSimpleProductSyncFlow(): void
    {
        // 1. Create simple product event data
        $productId = 5001;
        $productData = [
            'ID' => $productId,
            'post_type' => 'product',
            'post_name' => 'e2e-simple-widget',
            'post_title' => 'E2E Simple Widget',
            'post_content' => '<p>Full HTML widget description.</p>',
            'post_excerpt' => 'Short widget description.',
            'post_status' => 'publish',
            'product_type' => 'simple',
            'regular_price' => '29.99',
            'sale_price' => '19.99',
            'price' => '19.99',
            'sku' => 'E2E-SW-001',
            'manage_stock' => true,
            'stock_quantity' => 15,
            'stock_status' => 'instock',
            'backorders_allowed' => false,
            'weight' => '1.25',
            'dimensions' => ['length' => '12', 'width' => '8', 'height' => '4'],
            'category_ids' => [101],
            'tag_ids' => [201],
            'featured_image_url' => 'https://example.com/widget.jpg',
            'gallery_images' => [
                [
                    'attachment_id' => '9001',
                    'url' => 'https://example.com/widget-1.jpg',
                    'thumbnail_url' => 'https://example.com/widget-1-thumb.jpg',
                    'medium_url' => 'https://example.com/widget-1-med.jpg',
                    'large_url' => 'https://example.com/widget-1-large.jpg',
                    'alt_text' => 'Widget Front',
                    'caption' => 'Front view',
                    'position' => 1
                ]
            ],
            'attributes' => [
                [
                    'key' => 'pa_color',
                    'label' => 'Color',
                    'type' => 'taxonomy',
                    'values' => ['Blue', 'Green'],
                    'is_visible' => true,
                    'is_for_variations' => false,
                    'position' => 0
                ]
            ],
            'seo' => [
                'meta_title' => 'E2E Simple Widget Title',
                'meta_description' => 'Best widget ever'
            ]
        ];

        // Ensure category exists in content.taxonomies to satisfy FK link
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("INSERT INTO content.taxonomies (id, source_term_id, taxonomy_type, name, slug) VALUES (:uuid, :term_id, 'product_cat', 'Widgets Category', 'widgets-cat') ON CONFLICT DO NOTHING");
        $stmt->execute([
            'uuid' => \HSP\Core\Events\EventBuilder::generateUuidV7(),
            'term_id' => '101'
        ]);

        // 2. Publish outbox event
        $this->outbox->publishProduct($productData, 'commerce.product.created');

        // Verify outbox writes
        $stmt = $pdo->prepare("SELECT * FROM system.events WHERE aggregate_type = 'product' AND aggregate_id = :id");
        $stmt->execute(['id' => (string) $productId]);
        $event = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($event);

        // 3. Run Sync worker
        $this->runCommerceWorker();

        // 4. Assert PostgreSQL projections
        $stmt = $pdo->prepare("SELECT * FROM content.products WHERE source_post_id = :id");
        $stmt->execute(['id' => (string) $productId]);
        $productRow = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($productRow);
        $this->assertEquals('e2e-simple-widget', $productRow['slug']);
        $this->assertEquals('E2E Simple Widget', $productRow['name']);
        $this->assertEquals('19.9900', $productRow['price']);
        $this->assertEquals('E2E-SW-001', $productRow['sku']);
        $this->assertTrue((bool)$productRow['manage_stock']);
        $this->assertEquals(15, $productRow['stock_quantity']);

        // Check attributes projection
        $stmt = $pdo->prepare("SELECT * FROM content.product_attributes WHERE product_id = :pid");
        $stmt->execute(['pid' => $productRow['id']]);
        $attrRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $attrRows);
        $this->assertEquals('pa_color', $attrRows[0]['attribute_key']);
        $this->assertEquals('Color', $attrRows[0]['attribute_label']);

        // Check media projection
        $stmt = $pdo->prepare("SELECT * FROM content.product_media WHERE product_id = :pid ORDER BY position ASC");
        $stmt->execute(['pid' => $productRow['id']]);
        $mediaRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        // Note: product transformer adds featured image at position 0, gallery images follow
        $this->assertCount(2, $mediaRows);
        $this->assertEquals('https://example.com/widget.jpg', $mediaRows[0]['url']);
        $this->assertEquals('https://example.com/widget-1.jpg', $mediaRows[1]['url']);

        // Check category relationship
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM content.product_categories WHERE product_id = :pid");
        $stmt->execute(['pid' => $productRow['id']]);
        $this->assertEquals(1, $stmt->fetchColumn());

        // 5. REST Delivery API Assertions
        $deliveryClient = $this->getDeliveryApiClient();

        // 5.1 Test list PLP endpoint
        $response = $deliveryClient->get('api/v1/products');
        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertNotEmpty($body['data']);
        $this->assertFalse($body['meta']['has_more']);

        $apiProd = null;
        foreach ($body['data'] as $p) {
            if ($p['slug'] === 'e2e-simple-widget') {
                $apiProd = $p;
                break;
            }
        }
        $this->assertNotNull($apiProd);
        $this->assertEquals('E2E Simple Widget', $apiProd['name']);
        $this->assertEquals('19.9900', $apiProd['price']);

        // 5.2 Test detail PDP endpoint
        $response = $deliveryClient->get('api/v1/products', [
            'query' => ['slug' => 'e2e-simple-widget']
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $apiDetail = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals('e2e-simple-widget', $apiDetail['slug']);
        $this->assertCount(1, $apiDetail['attributes']);
        $this->assertCount(2, $apiDetail['media']);
        $this->assertEquals('E2E-SW-001', $apiDetail['sku']);

        // 6. Test Stock update sync flow
        $stockData = [
            'ID' => $productId,
            'post_type' => 'product',
            'stock_quantity' => 8,
            'stock_status' => 'instock',
            'manage_stock' => true
        ];
        $this->outbox->publishProduct($stockData, 'commerce.product.stock_updated');
        $this->runCommerceWorker();

        // Verify updated stock in Postgres
        $stmt = $pdo->prepare("SELECT stock_quantity FROM content.products WHERE id = :id");
        $stmt->execute(['id' => $productRow['id']]);
        $this->assertEquals(8, $stmt->fetchColumn());

        // 7. Test Soft Delete flow
        $delData = [
            'ID' => $productId,
            'post_type' => 'product',
            'post_status' => 'trash'
        ];
        $this->outbox->publishProduct($delData, 'commerce.product.deleted');
        $this->runCommerceWorker();

        // Verify soft deleted status
        $stmt = $pdo->prepare("SELECT status, deleted_at FROM content.products WHERE id = :id");
        $stmt->execute(['id' => $productRow['id']]);
        $delRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('trash', $delRow['status']);
        $this->assertNotNull($delRow['deleted_at']);

        // Verify detail PDP returns 404
        $response = $deliveryClient->get('api/v1/products', [
            'query' => ['slug' => 'e2e-simple-widget']
        ]);
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * Test Case 2: Sync variable product, variations, price min/max calculations.
     */
    public function testVariableProductSyncFlow(): void
    {
        $parentPostId = 6001;
        $var1Id = 6002;
        $var2Id = 6003;

        $parentData = [
            'ID' => $parentPostId,
            'post_type' => 'product',
            'post_name' => 'e2e-variable-shirt',
            'post_title' => 'E2E Variable Shirt',
            'post_content' => '<p>Shirt description.</p>',
            'product_type' => 'variable',
            'regular_price' => '', // variable products don't hold simple price
            'sale_price' => '',
            'price' => '',
            'sku' => 'E2E-VS-001',
            'featured_image_url' => 'https://example.com/shirt.jpg',
            'attributes' => [
                [
                    'key' => 'pa_size',
                    'label' => 'Size',
                    'type' => 'taxonomy',
                    'values' => ['S', 'M'],
                    'is_visible' => true,
                    'is_for_variations' => true,
                    'position' => 0
                ]
            ]
        ];

        // 1. Publish Parent Product
        $this->outbox->publishProduct($parentData, 'commerce.product.created');
        $this->runCommerceWorker();

        // 2. Publish Variation 1 (Size: S, Price: 15.00)
        $var1Data = [
            'variation_id' => $var1Id,
            'parent_product_id' => $parentPostId,
            'regular_price' => '15.00',
            'sale_price' => '',
            'price' => '15.00',
            'sku' => 'E2E-VS-001-S',
            'attributes' => ['pa_size' => 'S'],
            'is_enabled' => true,
            'menu_order' => 0
        ];
        $this->outbox->publishVariation($var1Data, 'commerce.product_variation.created');
        
        // 3. Publish Variation 2 (Size: M, Price: 18.00)
        $var2Data = [
            'variation_id' => $var2Id,
            'parent_product_id' => $parentPostId,
            'regular_price' => '18.00',
            'sale_price' => '',
            'price' => '18.00',
            'sku' => 'E2E-VS-001-M',
            'attributes' => ['pa_size' => 'M'],
            'is_enabled' => true,
            'menu_order' => 1
        ];
        $this->outbox->publishVariation($var2Data, 'commerce.product_variation.created');

        $this->runCommerceWorker();

        // 4. Verify projections and min/max aggregation
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT * FROM content.products WHERE source_post_id = :id");
        $stmt->execute(['id' => (string) $parentPostId]);
        $parentRow = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($parentRow);
        // Assert pre-computed price ranges are correct
        $this->assertEquals('15.0000', $parentRow['price_min']);
        $this->assertEquals('18.0000', $parentRow['price_max']);

        // Verify variation rows exist
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM content.product_variations WHERE product_id = :pid");
        $stmt->execute(['pid' => $parentRow['id']]);
        $this->assertEquals(2, $stmt->fetchColumn());

        // 5. Test detail PDP endpoint returns nested variations list
        $deliveryClient = $this->getDeliveryApiClient();
        $response = $deliveryClient->get('api/v1/products', [
            'query' => ['slug' => 'e2e-variable-shirt']
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $apiDetail = json_decode($response->getBody()->getContents(), true);

        $this->assertCount(2, $apiDetail['variations']);
        $this->assertEquals('E2E-VS-001-S', $apiDetail['variations'][0]['sku']);
        $this->assertEquals('S', $apiDetail['variations'][0]['attributes']['pa_size']);
        $this->assertEquals('E2E-VS-001-M', $apiDetail['variations'][1]['sku']);
        $this->assertEquals('M', $apiDetail['variations'][1]['attributes']['pa_size']);
    }
}
