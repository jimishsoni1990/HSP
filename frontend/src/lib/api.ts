export interface Category {
  id: string;
  source_term_id: string;
  taxonomy_type: string;
  slug: string;
  name: string;
  description: string;
  created_at: string;
  updated_at: string;
  seo?: {
    meta_title?: string;
    meta_description?: string;
    og_title?: string;
    og_description?: string;
    og_image?: string;
  };
}

export interface Post {
  id: string;
  source_post_id: string;
  source_entity_type: string;
  slug: string;
  title: string;
  excerpt: string;
  content: string;
  status: string;
  created_at: string;
  updated_at: string;
  categories?: Category[];
  featured_image_url?: string;
  seo?: {
    meta_title?: string;
    meta_description?: string;
    og_title?: string;
    og_description?: string;
    og_image?: string;
  };
}

export interface Page {
  id: string;
  source_post_id: string;
  source_entity_type: string;
  slug: string;
  title: string;
  content?: string;
  status: string;
  created_at: string;
  updated_at: string;
  seo?: {
    meta_title?: string;
    meta_description?: string;
    og_title?: string;
    og_description?: string;
    og_image?: string;
  };
}

export interface ProductAttribute {
  id: string;
  attribute_key: string;
  attribute_label: string;
  attribute_type: 'taxonomy' | 'custom';
  values: string[];
  is_visible: boolean;
  is_for_variations: boolean;
  position: number;
}

export interface ProductVariation {
  id: string;
  source_variation_id: string;
  regular_price: string | null;
  sale_price: string | null;
  price: string | null;
  sku: string;
  manage_stock: boolean;
  stock_quantity: number | null;
  stock_status: 'instock' | 'outofstock' | 'onbackorder';
  image_url: string | null;
  attributes: Record<string, string>;
  is_enabled: boolean;
}

export interface ProductMedia {
  id: string;
  url: string;
  thumbnail_url: string | null;
  medium_url: string | null;
  large_url: string | null;
  alt_text: string;
  is_featured: boolean;
  position: number;
}

export interface Product {
  id: string;
  slug: string;
  name: string;
  product_type: 'simple' | 'variable' | 'grouped' | 'external';
  description: string;
  short_description: string;
  status: string;
  price: string | null;
  price_min: string | null;
  price_max: string | null;
  regular_price: string | null;
  sale_price: string | null;
  sku: string;
  source_post_id: string;
  weight: string | null;
  dimensions: { length: string; width: string; height: string } | null;
  stock_status: 'instock' | 'outofstock' | 'onbackorder';
  stock_quantity: number | null;
  featured_image_url: string | null;
  external_url: string | null;
  button_text: string | null;
  media?: ProductMedia[];
  attributes?: ProductAttribute[];
  variations?: ProductVariation[];
  categories?: Category[];
  seo?: {
    meta_title?: string;
    meta_description?: string;
    og_title?: string;
    og_description?: string;
    og_image?: string;
  };
  created_at: string;
  updated_at: string;
}

export interface ProductListMeta {
  next_cursor: string | null;
  has_more: boolean;
  per_page: number;
}

export interface ProductListResponse {
  data: Product[];
  meta: ProductListMeta;
}

const API_BASE_URL = process.env.NEXT_PUBLIC_DELIVERY_API_URL || 'http://127.0.0.1:9000';

/**
 * Fetch helper with standard configurations.
 */
async function fetchAPI<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const url = `${API_BASE_URL}/${ltrim(endpoint, '/')}`;
  
  const res = await fetch(url, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      ...options.headers,
    },
    // Keep caching configurations aligned with Next.js standards
    next: { revalidate: 60 }, 
  });

  if (!res.ok) {
    if (res.status === 404) {
      throw new Error(`Resource not found at: ${url}`);
    }
    throw new Error(`API fetch failed: ${res.statusText} (${res.status})`);
  }

  return res.json();
}

function ltrim(str: string, char: string): string {
  return str.startsWith(char) ? str.slice(char.length) : str;
}

/**
 * HSP REST Delivery API Client methods.
 */
export const api = {
  /**
   * Get all published posts, optionally filtered by category slug.
   */
  async getPosts(categorySlug?: string): Promise<Post[]> {
    if (categorySlug) {
      return fetchAPI<Post[]>(`api/v1/posts?category=${encodeURIComponent(categorySlug)}`);
    }
    return fetchAPI<Post[]>('api/v1/posts');
  },

  /**
   * Fetch a single post by its unique slug.
   */
  async getPostBySlug(slug: string): Promise<Post | null> {
    try {
      return await fetchAPI<Post>(`api/v1/posts?slug=${encodeURIComponent(slug)}`);
    } catch (error) {
      return null;
    }
  },

  /**
   * Fetch a single static page by its slug.
   */
  async getPageBySlug(slug: string): Promise<Page | null> {
    try {
      return await fetchAPI<Page>(`api/v1/pages?slug=${encodeURIComponent(slug)}`);
    } catch (error) {
      return null;
    }
  },

  /**
   * Fetch all active categories.
   */
  async getCategories(): Promise<Category[]> {
    return fetchAPI<Category[]>('api/v1/categories');
  },

  /**
   * Get products with cursor-based pagination, sorting, and attribute filtering.
   */
  async getProducts(params?: {
    category?: string;
    type?: string;
    min_price?: number;
    max_price?: number;
    in_stock?: boolean;
    sort?: 'price_asc' | 'price_desc' | 'date_asc' | 'date_desc' | 'name_asc';
    cursor?: string;
    per_page?: number;
    [key: string]: any;
  }): Promise<ProductListResponse> {
    const query = new URLSearchParams();
    if (params) {
      Object.entries(params).forEach(([key, val]) => {
        if (val !== undefined && val !== null && val !== '') {
          if (typeof val === 'boolean') {
            query.append(key, val ? '1' : '0');
          } else {
            query.append(key, String(val));
          }
        }
      });
    }
    const queryString = query.toString();
    const endpoint = queryString ? `api/v1/products?${queryString}` : 'api/v1/products';
    return fetchAPI<ProductListResponse>(endpoint);
  },

  /**
   * Fetch a single product by its slug (PDP).
   */
  async getProductBySlug(slug: string): Promise<Product | null> {
    try {
      return await fetchAPI<Product>(`api/v1/products?slug=${encodeURIComponent(slug)}`);
    } catch (error) {
      return null;
    }
  },

  /**
   * Fetch all WooCommerce product categories.
   */
  async getProductCategories(): Promise<Category[]> {
    return fetchAPI<Category[]>('api/v1/products/categories');
  },
};
