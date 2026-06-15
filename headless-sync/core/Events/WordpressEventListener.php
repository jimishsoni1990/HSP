<?php

namespace HSP\Core\Events;

class WordpressEventListener
{
    /**
     * @var OutboxService
     */
    protected OutboxService $outbox;

    /**
     * WordpressEventListener constructor.
     *
     * @param OutboxService $outbox
     */
    public function __construct(OutboxService $outbox)
    {
        $this->outbox = $outbox;
    }

    /**
     * Register WordPress hooks.
     *
     * @return void
     */
    public function registerHooks(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('wp_after_insert_post', [$this, 'handlePostSave'], 10, 4);
        add_action('before_delete_post', [$this, 'handlePostDelete'], 10, 2);
        add_action('saved_category', [$this, 'handleCategorySave'], 10, 3);
        add_action('pre_delete_term', [$this, 'handleCategoryDelete'], 10, 2);

        if (function_exists('add_filter')) {
            add_filter('hsp_sync_post_seo_data', [$this, 'defaultSeoMapping'], 10, 3);
            add_filter('hsp_sync_term_seo_data', [$this, 'defaultTermSeoMapping'], 10, 3);
        }
    }

    /**
     * Handle post insertion/update hooks.
     *
     * @param int $postId
     * @param mixed $post
     * @param bool $update
     * @param mixed $postBefore
     * @return void
     */
    public function handlePostSave(int $postId, $post, bool $update, $postBefore = null): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (function_exists('wp_is_post_revision') && wp_is_post_revision($postId)) {
            return;
        }

        $status = $post->post_status ?? '';
        $type = $post->post_type ?? '';

        if (!in_array($type, ['post', 'page'])) {
            return;
        }

        // Only sync published state or transition out of published
        if ($status !== 'publish' && $status !== 'trash') {
            return;
        }

        $eventPrefix = $type === 'page' ? 'content.page' : 'content.post';
        $eventType = $update ? $eventPrefix . '.updated' : $eventPrefix . '.created';

        $categories = [];
        if (function_exists('wp_get_post_categories')) {
            $categories = wp_get_post_categories($postId);
        }

        $postData = [
            'ID' => $postId,
            'post_author' => $post->post_author,
            'post_date' => $post->post_date,
            'post_date_gmt' => $post->post_date_gmt,
            'post_content' => $post->post_content,
            'post_title' => $post->post_title,
            'post_excerpt' => $post->post_excerpt,
            'post_status' => $post->post_status,
            'comment_status' => $post->comment_status,
            'ping_status' => $post->ping_status,
            'post_name' => $post->post_name,
            'post_modified' => $post->post_modified,
            'post_modified_gmt' => $post->post_modified_gmt,
            'post_parent' => $post->post_parent,
            'guid' => $post->guid,
            'menu_order' => $post->menu_order,
            'post_type' => $post->post_type,
            'post_mime_type' => $post->post_mime_type,
            'categories' => $categories,
        ];

        $seoData = [
            'meta_title'       => '',
            'meta_description' => '',
            'og_title'         => '',
            'og_description'   => '',
            'og_image'         => '',
        ];
        if (function_exists('apply_filters')) {
            $seoData = apply_filters('hsp_sync_post_seo_data', $seoData, $postId, $post);
        }
        $postData['seo'] = $seoData;

        try {
            $this->outbox->publishPost($postData, $eventType);
        } catch (\Throwable $e) {
            // Log/handle error
        }
    }

    /**
     * Default callback to extract SEO data from Yoast SEO if active.
     *
     * @param array $seoData
     * @param int $postId
     * @param mixed $post
     * @return array
     */
    public function defaultSeoMapping(array $seoData, int $postId, $post): array
    {
        if (function_exists('get_post_meta')) {
            $seoData['meta_title']       = get_post_meta($postId, '_yoast_wpseo_title', true) ?: '';
            $seoData['meta_description'] = get_post_meta($postId, '_yoast_wpseo_metadesc', true) ?: '';
            $seoData['og_title']         = get_post_meta($postId, '_yoast_wpseo_opengraph-title', true) ?: '';
            $seoData['og_description']   = get_post_meta($postId, '_yoast_wpseo_opengraph-description', true) ?: '';
            $seoData['og_image']         = get_post_meta($postId, '_yoast_wpseo_opengraph-image', true) ?: '';
        }
        return $seoData;
    }

    /**
     * Handle post deletion hooks.
     *
     * @param int $postId
     * @param mixed $post
     * @return void
     */
    public function handlePostDelete(int $postId, $post = null): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!$post && function_exists('get_post')) {
            $post = get_post($postId);
        }

        if (!$post) {
            return;
        }

        $type = $post->post_type ?? '';
        if (!in_array($type, ['post', 'page'])) {
            return;
        }

        $postData = [
            'ID' => $postId,
            'post_type' => $type,
        ];

        try {
            $eventType = $type === 'page' ? 'content.page.deleted' : 'content.post.deleted';
            $this->outbox->publishPost($postData, $eventType);
        } catch (\Throwable $e) {
            // Log/handle error
        }
    }

    /**
     * Handle category (term) save hooks.
     *
     * @param int $termId
     * @param int $ttId
     * @param bool $update
     * @return void
     */
    public function handleCategorySave(int $termId, int $ttId, bool $update): void
    {
        if (!function_exists('get_term')) {
            return;
        }

        $term = get_term($termId, 'category');
        if (!$term || is_wp_error($term)) {
            return;
        }

        $termData = [
            'term_id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'parent' => $term->parent,
            'count' => $term->count,
        ];

        $seoData = [
            'meta_title'       => '',
            'meta_description' => '',
            'og_title'         => '',
            'og_description'   => '',
            'og_image'         => '',
        ];
        if (function_exists('apply_filters')) {
            $seoData = apply_filters('hsp_sync_term_seo_data', $seoData, $termId, 'category');
        }
        $termData['seo'] = $seoData;

        $eventType = $update ? 'content.category.updated' : 'content.category.created';

        try {
            $this->outbox->publishTerm($termData, $eventType, 'category');
        } catch (\Throwable $e) {
            // Log/handle error
        }
    }

    /**
     * Handle category (term) deletion hooks.
     *
     * @param int $termId
     * @param string $taxonomy
     * @return void
     */
    public function handleCategoryDelete(int $termId, string $taxonomy): void
    {
        if ($taxonomy !== 'category') {
            return;
        }

        $termData = [
            'term_id' => $termId,
        ];

        try {
            $this->outbox->publishTerm($termData, 'content.category.deleted', 'category');
        } catch (\Throwable $e) {
            // Log/handle error
        }
    }

    /**
     * Default callback to extract term (category) SEO data from Yoast SEO option if active.
     *
     * @param array $seoData
     * @param int $termId
     * @param string $taxonomy
     * @return array
     */
    public function defaultTermSeoMapping(array $seoData, int $termId, string $taxonomy): array
    {
        if (function_exists('get_option')) {
            $taxMeta = get_option('wpseo_taxonomy_meta');
            if ($taxMeta && isset($taxMeta[$taxonomy][$termId])) {
                $termMeta = $taxMeta[$taxonomy][$termId];
                $seoData['meta_title']       = $termMeta['wpseo_title'] ?? '';
                $seoData['meta_description'] = $termMeta['wpseo_desc'] ?? '';
                $seoData['og_title']         = $termMeta['wpseo_opengraph-title'] ?? '';
                $seoData['og_description']   = $termMeta['wpseo_opengraph-description'] ?? '';
                $seoData['og_image']         = $termMeta['wpseo_opengraph-image'] ?? '';
            }
        }
        return $seoData;
    }
}
