<?php
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

header("HTTP/1.1 404 Not Found");
header("Content-Type: application/json");
echo json_encode(["error" => "Endpoint not found"]);
