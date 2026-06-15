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
};
