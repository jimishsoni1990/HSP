<?php

namespace HSP\Modules\Commerce\Transformers;

class ProductMediaTransformer
{
    /**
     * Convert raw gallery images payload arrays into a structured normalized array format.
     *
     * @param array $galleryImages
     * @return array
     */
    public static function fromGallery(array $galleryImages): array
    {
        $media = [];
        foreach ($galleryImages as $img) {
            $media[] = [
                'sourceAttachmentId' => (string) ($img['attachment_id'] ?? ''),
                'url'                => (string) ($img['url'] ?? ''),
                'thumbnailUrl'       => isset($img['thumbnail_url']) ? (string) $img['thumbnail_url'] : null,
                'mediumUrl'          => isset($img['medium_url']) ? (string) $img['medium_url'] : null,
                'largeUrl'           => isset($img['large_url']) ? (string) $img['large_url'] : null,
                'altText'            => isset($img['alt_text']) ? (string) $img['alt_text'] : null,
                'caption'            => isset($img['caption']) ? (string) $img['caption'] : null,
                'position'           => (int) ($img['position'] ?? 0),
                'isFeatured'         => (bool) ($img['is_featured'] ?? false),
            ];
        }
        return $media;
    }
}
