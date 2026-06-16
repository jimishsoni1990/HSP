<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$host = getenv('PG_DB_HOST') ?: '127.0.0.1';
$port = getenv('PG_DB_PORT') ?: '5433';
$db = getenv('PG_DB_NAME') ?: 'hsp_delivery';
$user = getenv('PG_DB_USER') ?: 'hsp_admin';
$pass = getenv('PG_DB_PASSWORD') ?: 'hsp_secret';

$dsn = "pgsql:host={$host};port={$port};dbname={$db}";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    header("HTTP/1.1 500 Internal Server Error");
    header("Content-Type: application/json");
    echo json_encode(["error" => "Database connection failed: " . $e->getMessage()]);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = ltrim($uri, '/');

if ($uri === '') {
    header("Content-Type: application/json");
    echo json_encode(["status" => "ok"]);
    exit;
}

if ($uri === 'api/v1/posts') {
    $slug = $_GET['slug'] ?? null;
    $categorySlug = $_GET['category'] ?? null;

    if ($slug) {
        $stmt = $pdo->prepare("SELECT * FROM content.posts WHERE slug = :slug AND deleted_at IS NULL AND status = 'publish'");
        $stmt->execute(['slug' => $slug]);
        $post = $stmt->fetch();

        if (!$post) {
            header("HTTP/1.1 404 Not Found");
            header("Content-Type: application/json");
            echo json_encode(["error" => "Post not found"]);
            exit;
        }

        // Get categories
        $stmt = $pdo->prepare("
            SELECT t.* FROM content.taxonomies t
            JOIN content.entity_taxonomies et ON t.id = et.taxonomy_id
            WHERE et.entity_id = :post_id AND t.deleted_at IS NULL
        ");
        $stmt->execute(['post_id' => $post['id']]);
        $post['categories'] = $stmt->fetchAll();
        foreach ($post['categories'] as &$cat) {
            if (isset($cat['seo']) && is_string($cat['seo'])) {
                $cat['seo'] = json_decode($cat['seo'], true);
            }
        }

        if (isset($post['seo']) && is_string($post['seo'])) {
            $post['seo'] = json_decode($post['seo'], true);
        }

        header("Content-Type: application/json");
        echo json_encode($post);
        exit;
    }

    if ($categorySlug) {
        // Find category UUID
        $stmt = $pdo->prepare("SELECT id FROM content.taxonomies WHERE slug = :slug AND deleted_at IS NULL");
        $stmt->execute(['slug' => $categorySlug]);
        $catUuid = $stmt->fetchColumn();

        if (!$catUuid) {
            header("Content-Type: application/json");
            echo json_encode([]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT p.* FROM content.posts p
            JOIN content.entity_taxonomies et ON p.id = et.entity_id
            WHERE et.taxonomy_id = :cat_uuid AND p.deleted_at IS NULL AND p.status = 'publish'
        ");
        $stmt->execute(['cat_uuid' => $catUuid]);
        $posts = $stmt->fetchAll();

        header("Content-Type: application/json");
        echo json_encode($posts);
        exit;
    }

    // List all published posts
    $stmt = $pdo->query("SELECT * FROM content.posts WHERE deleted_at IS NULL AND status = 'publish' ORDER BY created_at DESC");
    $posts = $stmt->fetchAll();

    foreach ($posts as &$post) {
        if (isset($post['seo']) && is_string($post['seo'])) {
            $post['seo'] = json_decode($post['seo'], true);
        }
        $stmtCat = $pdo->prepare("
            SELECT t.* FROM content.taxonomies t
            JOIN content.entity_taxonomies et ON t.id = et.taxonomy_id
            WHERE et.entity_id = :post_id AND t.deleted_at IS NULL
        ");
        $stmtCat->execute(['post_id' => $post['id']]);
        $post['categories'] = $stmtCat->fetchAll();
        foreach ($post['categories'] as &$cat) {
            if (isset($cat['seo']) && is_string($cat['seo'])) {
                $cat['seo'] = json_decode($cat['seo'], true);
            }
        }
    }

    header("Content-Type: application/json");
    echo json_encode($posts);
    exit;
}

if ($uri === 'api/v1/pages') {
    $slug = $_GET['slug'] ?? null;

    if ($slug) {
        $stmt = $pdo->prepare("SELECT * FROM content.pages WHERE slug = :slug AND deleted_at IS NULL AND status = 'publish'");
        $stmt->execute(['slug' => $slug]);
        $page = $stmt->fetch();

        if (!$page) {
            header("HTTP/1.1 404 Not Found");
            header("Content-Type: application/json");
            echo json_encode(["error" => "Page not found"]);
            exit;
        }

        if (isset($page['seo']) && is_string($page['seo'])) {
            $page['seo'] = json_decode($page['seo'], true);
        }

        header("Content-Type: application/json");
        echo json_encode($page);
        exit;
    }

    // List all published pages
    $stmt = $pdo->query("SELECT * FROM content.pages WHERE deleted_at IS NULL AND status = 'publish' ORDER BY created_at DESC");
    $pages = $stmt->fetchAll();

    foreach ($pages as &$page) {
        if (isset($page['seo']) && is_string($page['seo'])) {
            $page['seo'] = json_decode($page['seo'], true);
        }
    }

    header("Content-Type: application/json");
    echo json_encode($pages);
    exit;
}

if ($uri === 'api/v1/categories') {
    $slug = $_GET['slug'] ?? null;

    if ($slug) {
        $stmt = $pdo->prepare("SELECT * FROM content.taxonomies WHERE slug = :slug AND deleted_at IS NULL");
        $stmt->execute(['slug' => $slug]);
        $cat = $stmt->fetch();

        if (!$cat) {
            header("HTTP/1.1 404 Not Found");
            header("Content-Type: application/json");
            echo json_encode(["error" => "Category not found"]);
            exit;
        }

        if (isset($cat['seo']) && is_string($cat['seo'])) {
            $cat['seo'] = json_decode($cat['seo'], true);
        }

        header("Content-Type: application/json");
        echo json_encode($cat);
        exit;
    }

    // List all active categories
    $stmt = $pdo->query("SELECT * FROM content.taxonomies WHERE deleted_at IS NULL ORDER BY name ASC");
    $cats = $stmt->fetchAll();

    foreach ($cats as &$c) {
        if (isset($c['seo']) && is_string($c['seo'])) {
            $c['seo'] = json_decode($c['seo'], true);
        }
    }

    header("Content-Type: application/json");
    echo json_encode($cats);
    exit;
}

if ($uri === 'api/v1/products') {
    $slug = $_GET['slug'] ?? null;

    if ($slug) {
        $stmt = $pdo->prepare("SELECT * FROM content.products WHERE slug = :slug AND deleted_at IS NULL AND status = 'publish'");
        $stmt->execute(['slug' => $slug]);
        $product = $stmt->fetch();

        if (!$product) {
            header("HTTP/1.1 404 Not Found");
            header("Content-Type: application/json");
            echo json_encode(["error" => "Product not found"]);
            exit;
        }

        $uuid = $product['id'];

        // Get attributes
        $stmt = $pdo->prepare("SELECT * FROM content.product_attributes WHERE product_id = :product_id ORDER BY position ASC");
        $stmt->execute(['product_id' => $uuid]);
        $product['attributes'] = $stmt->fetchAll();
        foreach ($product['attributes'] as &$attr) {
            if (isset($attr['values']) && is_string($attr['values'])) {
                $attr['values'] = json_decode($attr['values'], true);
            }
        }

        // Get variations
        $stmt = $pdo->prepare("SELECT * FROM content.product_variations WHERE product_id = :product_id AND is_enabled = TRUE ORDER BY menu_order ASC, id ASC");
        $stmt->execute(['product_id' => $uuid]);
        $product['variations'] = $stmt->fetchAll();
        foreach ($product['variations'] as &$var) {
            if (isset($var['attributes']) && is_string($var['attributes'])) {
                $var['attributes'] = json_decode($var['attributes'], true);
            }
            $var['manage_stock'] = (bool) ($var['manage_stock'] ?? false);
            $var['is_enabled'] = (bool) ($var['is_enabled'] ?? true);
        }

        // Get media
        $stmt = $pdo->prepare("SELECT * FROM content.product_media WHERE product_id = :product_id ORDER BY position ASC");
        $stmt->execute(['product_id' => $uuid]);
        $product['media'] = $stmt->fetchAll();
        foreach ($product['media'] as &$med) {
            $med['is_featured'] = (bool) ($med['is_featured'] ?? false);
        }

        // Get categories
        $stmt = $pdo->prepare("
            SELECT t.* FROM content.taxonomies t
            JOIN content.product_categories pc ON t.id = pc.taxonomy_id
            WHERE pc.product_id = :product_id AND t.deleted_at IS NULL
        ");
        $stmt->execute(['product_id' => $uuid]);
        $product['categories'] = $stmt->fetchAll();
        foreach ($product['categories'] as &$cat) {
            if (isset($cat['seo']) && is_string($cat['seo'])) {
                $cat['seo'] = json_decode($cat['seo'], true);
            }
        }

        // Decode product JSON fields
        foreach (['grouped_product_ids', 'category_ids', 'tag_ids', 'dimensions', 'seo'] as $field) {
            if (isset($product[$field]) && is_string($product[$field])) {
                $product[$field] = json_decode($product[$field], true);
            }
        }
        $product['manage_stock'] = (bool) ($product['manage_stock'] ?? false);
        $product['backorders_allowed'] = (bool) ($product['backorders_allowed'] ?? false);

        header("Content-Type: application/json");
        echo json_encode($product);
        exit;
    }

    // List products (No variations nested)
    $categorySlug = $_GET['category'] ?? null;
    $type = $_GET['type'] ?? null;
    $minPrice = isset($_GET['min_price']) ? (float) $_GET['min_price'] : null;
    $maxPrice = isset($_GET['max_price']) ? (float) $_GET['max_price'] : null;
    $inStock = isset($_GET['in_stock']) && $_GET['in_stock'] == '1';
    $sort = $_GET['sort'] ?? 'date_desc';
    $cursor = $_GET['cursor'] ?? null;
    $perPage = (int) ($_GET['per_page'] ?? 20);

    if ($perPage < 1 || $perPage > 100) {
        $perPage = 20;
    }

    $cursorData = null;
    if ($cursor) {
        $decoded = base64_decode($cursor);
        if ($decoded) {
            $cursorData = json_decode($decoded, true);
        }
    }

    $where = ["p.deleted_at IS NULL", "p.status = 'publish'"];
    $params = [];

    // Category filter
    if ($categorySlug) {
        $stmt = $pdo->prepare("SELECT id FROM content.taxonomies WHERE slug = :slug AND taxonomy = 'product_cat' AND deleted_at IS NULL");
        $stmt->execute(['slug' => $categorySlug]);
        $catUuid = $stmt->fetchColumn();

        if ($catUuid) {
            $where[] = "p.id IN (SELECT product_id FROM content.product_categories WHERE taxonomy_id = :cat_uuid)";
            $params['cat_uuid'] = $catUuid;
        } else {
            // Category filter requested but not found, return empty set
            header("Content-Type: application/json");
            echo json_encode(["data" => [], "meta" => ["next_cursor" => null, "has_more" => false, "per_page" => $perPage]]);
            exit;
        }
    }

    // Filters
    if ($type) {
        $where[] = "p.product_type = :type";
        $params['type'] = $type;
    }
    if ($minPrice !== null) {
        $where[] = "p.price >= :min_price";
        $params['min_price'] = $minPrice;
    }
    if ($maxPrice !== null) {
        $where[] = "p.price <= :max_price";
        $params['max_price'] = $maxPrice;
    }
    if ($inStock) {
        $where[] = "p.stock_status = 'instock'";
    }

    // Dynamic Attribute filters: e.g. attr_pa_color=Blue
    foreach ($_GET as $key => $val) {
        if (strpos($key, 'attr_') === 0 && !empty($val)) {
            $attrKey = substr($key, 5); // remove attr_ prefix
            $paramName = 'attr_val_' . md5($key);
            $where[] = "p.id IN (
                SELECT product_id FROM content.product_attributes 
                WHERE attribute_key = " . $pdo->quote($attrKey) . " 
                  AND values @> :{$paramName}
            )";
            $params[$paramName] = json_encode([$val]);
        }
    }

    // Sort mappings & cursor-based pagination
    $orderBy = "";
    switch ($sort) {
        case 'price_asc':
            $orderBy = "p.price ASC, p.id ASC";
            if ($cursorData && isset($cursorData['price']) && isset($cursorData['id'])) {
                $where[] = "(p.price > :c_price OR (p.price = :c_price AND p.id > :c_id))";
                $params['c_price'] = $cursorData['price'];
                $params['c_id'] = $cursorData['id'];
            }
            break;
        case 'price_desc':
            $orderBy = "p.price DESC, p.id DESC";
            if ($cursorData && isset($cursorData['price']) && isset($cursorData['id'])) {
                $where[] = "(p.price < :c_price OR (p.price = :c_price AND p.id < :c_id))";
                $params['c_price'] = $cursorData['price'];
                $params['c_id'] = $cursorData['id'];
            }
            break;
        case 'date_asc':
            $orderBy = "p.created_at ASC, p.id ASC";
            if ($cursorData && isset($cursorData['created_at']) && isset($cursorData['id'])) {
                $where[] = "(p.created_at > :c_created OR (p.created_at = :c_created AND p.id > :c_id))";
                $params['c_created'] = $cursorData['created_at'];
                $params['c_id'] = $cursorData['id'];
            }
            break;
        case 'name_asc':
            $orderBy = "p.name ASC, p.id ASC";
            if ($cursorData && isset($cursorData['name']) && isset($cursorData['id'])) {
                $where[] = "(p.name > :c_name OR (p.name = :c_name AND p.id > :c_id))";
                $params['c_name'] = $cursorData['name'];
                $params['c_id'] = $cursorData['id'];
            }
            break;
        case 'date_desc':
        default:
            $orderBy = "p.created_at DESC, p.id DESC";
            if ($cursorData && isset($cursorData['created_at']) && isset($cursorData['id'])) {
                $where[] = "(p.created_at < :c_created OR (p.created_at = :c_created AND p.id < :c_id))";
                $params['c_created'] = $cursorData['created_at'];
                $params['c_id'] = $cursorData['id'];
            }
            break;
    }

    $whereClause = implode(" AND ", $where);
    $fetchLimit = $perPage + 1; // Retrieve 1 extra row to check has_more

    $sql = "SELECT p.* FROM content.products p WHERE {$whereClause} ORDER BY {$orderBy} LIMIT {$fetchLimit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    $hasMore = count($products) > $perPage;
    if ($hasMore) {
        array_pop($products); // Remove the extra row
    }

    // Format list response
    foreach ($products as &$product) {
        foreach (['grouped_product_ids', 'category_ids', 'tag_ids', 'dimensions', 'seo'] as $field) {
            if (isset($product[$field]) && is_string($product[$field])) {
                $product[$field] = json_decode($product[$field], true);
            }
        }
        $product['manage_stock'] = (bool) ($product['manage_stock'] ?? false);
        $product['backorders_allowed'] = (bool) ($product['backorders_allowed'] ?? false);

        // Fetch primary categories array
        $stmtCat = $pdo->prepare("
            SELECT t.* FROM content.taxonomies t
            JOIN content.product_categories pc ON t.id = pc.taxonomy_id
            WHERE pc.product_id = :product_id AND t.deleted_at IS NULL
        ");
        $stmtCat->execute(['product_id' => $product['id']]);
        $product['categories'] = $stmtCat->fetchAll();
        foreach ($product['categories'] as &$cat) {
            if (isset($cat['seo']) && is_string($cat['seo'])) {
                $cat['seo'] = json_decode($cat['seo'], true);
            }
        }
    }

    $nextCursor = null;
    if ($hasMore && !empty($products)) {
        $lastProduct = end($products);
        $cursorPayload = ['id' => $lastProduct['id']];
        if ($sort === 'price_asc' || $sort === 'price_desc') {
            $cursorPayload['price'] = $lastProduct['price'];
        } elseif ($sort === 'date_asc' || $sort === 'date_desc') {
            $cursorPayload['created_at'] = $lastProduct['created_at'];
        } elseif ($sort === 'name_asc') {
            $cursorPayload['name'] = $lastProduct['name'];
        }
        $nextCursor = base64_encode(json_encode($cursorPayload));
    }

    header("Content-Type: application/json");
    echo json_encode([
        "data" => $products,
        "meta" => [
            "next_cursor" => $nextCursor,
            "has_more" => $hasMore,
            "per_page" => $perPage
        ]
    ]);
    exit;
}

if ($uri === 'api/v1/products/categories') {
    $stmt = $pdo->query("SELECT * FROM content.taxonomies WHERE taxonomy = 'product_cat' AND deleted_at IS NULL ORDER BY name ASC");
    $cats = $stmt->fetchAll();

    foreach ($cats as &$c) {
        if (isset($c['seo']) && is_string($c['seo'])) {
            $c['seo'] = json_decode($c['seo'], true);
        }
    }

    header("Content-Type: application/json");
    echo json_encode($cats);
    exit;
}

if ($uri === 'api/v1/products/export') {
    $cursor = $_GET['cursor'] ?? null;
    $batchSize = (int) ($_GET['batch_size'] ?? 100);

    if ($batchSize < 1 || $batchSize > 500) {
        $batchSize = 100;
    }

    $where = ["p.deleted_at IS NULL", "p.status = 'publish'"];
    $params = [];

    if ($cursor) {
        $decoded = base64_decode($cursor);
        if ($decoded) {
            $cursorData = json_decode($decoded, true);
            if (isset($cursorData['id'])) {
                $where[] = "p.id > :c_id";
                $params['c_id'] = $cursorData['id'];
            }
        }
    }

    $whereClause = implode(" AND ", $where);
    $fetchLimit = $batchSize + 1;

    $sql = "SELECT p.* FROM content.products p WHERE {$whereClause} ORDER BY p.id ASC LIMIT {$fetchLimit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    $hasMore = count($products) > $batchSize;
    if ($hasMore) {
        array_pop($products);
    }

    foreach ($products as &$product) {
        $uuid = $product['id'];

        // Nest attributes
        $stmtAttr = $pdo->prepare("SELECT * FROM content.product_attributes WHERE product_id = :product_id ORDER BY position ASC");
        $stmtAttr->execute(['product_id' => $uuid]);
        $product['attributes'] = $stmtAttr->fetchAll();
        foreach ($product['attributes'] as &$attr) {
            if (isset($attr['values']) && is_string($attr['values'])) {
                $attr['values'] = json_decode($attr['values'], true);
            }
        }

        // Nest variations
        $stmtVar = $pdo->prepare("SELECT * FROM content.product_variations WHERE product_id = :product_id AND is_enabled = TRUE ORDER BY menu_order ASC, id ASC");
        $stmtVar->execute(['product_id' => $uuid]);
        $product['variations'] = $stmtVar->fetchAll();
        foreach ($product['variations'] as &$var) {
            if (isset($var['attributes']) && is_string($var['attributes'])) {
                $var['attributes'] = json_decode($var['attributes'], true);
            }
            $var['manage_stock'] = (bool) ($var['manage_stock'] ?? false);
            $var['is_enabled'] = (bool) ($var['is_enabled'] ?? true);
        }

        // Nest media
        $stmtMed = $pdo->prepare("SELECT * FROM content.product_media WHERE product_id = :product_id ORDER BY position ASC");
        $stmtMed->execute(['product_id' => $uuid]);
        $product['media'] = $stmtMed->fetchAll();
        foreach ($product['media'] as &$med) {
            $med['is_featured'] = (bool) ($med['is_featured'] ?? false);
        }

        // Nest categories
        $stmtCat = $pdo->prepare("
            SELECT t.* FROM content.taxonomies t
            JOIN content.product_categories pc ON t.id = pc.taxonomy_id
            WHERE pc.product_id = :product_id AND t.deleted_at IS NULL
        ");
        $stmtCat->execute(['product_id' => $uuid]);
        $product['categories'] = $stmtCat->fetchAll();
        foreach ($product['categories'] as &$cat) {
            if (isset($cat['seo']) && is_string($cat['seo'])) {
                $cat['seo'] = json_decode($cat['seo'], true);
            }
        }

        // Decode product JSON fields
        foreach (['grouped_product_ids', 'category_ids', 'tag_ids', 'dimensions', 'seo'] as $field) {
            if (isset($product[$field]) && is_string($product[$field])) {
                $product[$field] = json_decode($product[$field], true);
            }
        }
        $product['manage_stock'] = (bool) ($product['manage_stock'] ?? false);
        $product['backorders_allowed'] = (bool) ($product['backorders_allowed'] ?? false);
    }

    $nextCursor = null;
    if ($hasMore && !empty($products)) {
        $lastProduct = end($products);
        $nextCursor = base64_encode(json_encode(['id' => $lastProduct['id']]));
    }

    header("Content-Type: application/json");
    echo json_encode([
        "data" => $products,
        "meta" => [
            "next_cursor" => $nextCursor,
            "has_more" => $hasMore
        ]
    ]);
    exit;
}

header("HTTP/1.1 404 Not Found");
header("Content-Type: application/json");
echo json_encode(["error" => "Endpoint not found"]);
