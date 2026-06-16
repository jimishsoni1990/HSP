import Link from "next/link";
import { api, Category, ProductListResponse } from "@/lib/api";
import ProductCard from "@/components/ProductCard";

export const revalidate = 60; // ISR revalidate every 60s

interface PageProps {
  searchParams: Promise<{
    category?: string;
    type?: string;
    min_price?: string;
    max_price?: string;
    in_stock?: string;
    sort?: 'price_asc' | 'price_desc' | 'date_asc' | 'date_desc' | 'name_asc';
    cursor?: string;
    per_page?: string;
  }>;
}

export default async function ProductsPage({ searchParams }: PageProps) {
  const params = await searchParams;

  const category = params.category || "";
  const sort = params.sort || "date_desc";
  const cursor = params.cursor || "";
  const minPrice = params.min_price ? parseFloat(params.min_price) : undefined;
  const maxPrice = params.max_price ? parseFloat(params.max_price) : undefined;
  const inStock = params.in_stock === "1";

  let productsResponse: ProductListResponse = { data: [], meta: { next_cursor: null, has_more: false, per_page: 20 } };
  let categories: Category[] = [];
  let errorMsg = null;

  try {
    const [fetchedProducts, fetchedCategories] = await Promise.all([
      api.getProducts({
        category,
        sort,
        cursor,
        min_price: minPrice,
        max_price: maxPrice,
        in_stock: inStock,
        per_page: 20
      }),
      api.getProductCategories()
    ]);
    productsResponse = fetchedProducts;
    categories = fetchedCategories;
  } catch (e: any) {
    errorMsg = e.message || "Failed to load store catalog.";
  }

  const products = productsResponse.data || [];
  const meta = productsResponse.meta;

  return (
    <div className="space-y-10">
      {/* Heading */}
      <div className="space-y-2">
        <h1 className="text-4xl sm:text-5xl font-display font-bold tracking-tight">
          HSP Commerce <span className="text-accent">Store</span>
        </h1>
        <p className="text-muted text-lg font-light">
          WooCommerce catalog synchronized unidirectionally to PostgreSQL.
        </p>
      </div>

      {errorMsg ? (
        <div className="text-center py-20">
          <div className="inline-block p-6 rounded-lg bg-red-950/20 border border-red-900/40 text-red-400">
            <p className="font-semibold">Error Loading Catalog</p>
            <p className="text-sm mt-1">{errorMsg}</p>
          </div>
        </div>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 pt-4">
          
          {/* Sidebar Filters */}
          <aside className="lg:col-span-3 space-y-6">
            {/* Category Filter */}
            <div className="glass-card p-5 space-y-4 border border-card-border">
              <h2 className="font-display font-semibold text-white tracking-wide text-sm uppercase">
                Categories
              </h2>
              <div className="flex flex-col gap-2">
                <Link
                  href="/products"
                  className={`text-xs font-medium px-3.5 py-2.5 rounded-lg border transition-all duration-200 ${
                    !category
                      ? "bg-accent border-accent text-black font-semibold"
                      : "bg-card-bg border-card-border text-muted hover:border-accent hover:text-accent"
                  }`}
                >
                  All Products
                </Link>
                {categories.map((cat) => (
                  <Link
                    key={cat.id}
                    href={`/products?category=${cat.slug}&sort=${sort}`}
                    className={`text-xs font-medium px-3.5 py-2.5 rounded-lg border transition-all duration-200 ${
                      category === cat.slug
                        ? "bg-accent border-accent text-black font-semibold"
                        : "bg-card-bg border-card-border text-muted hover:border-accent hover:text-accent"
                    }`}
                  >
                    {cat.name}
                  </Link>
                ))}
              </div>
            </div>

            {/* In Stock toggle & reset filters */}
            <div className="glass-card p-5 space-y-4 border border-card-border">
              <h2 className="font-display font-semibold text-white tracking-wide text-sm uppercase">
                Filters
              </h2>
              <div className="flex flex-col gap-3">
                <Link
                  href={`/products?category=${category}&sort=${sort}&in_stock=${inStock ? "0" : "1"}`}
                  className={`text-xs font-medium px-3.5 py-2.5 rounded-lg border text-center transition-all duration-200 ${
                    inStock
                      ? "bg-accent border-accent text-black font-semibold"
                      : "bg-card-bg border-card-border text-muted hover:border-accent hover:text-accent"
                  }`}
                >
                  {inStock ? "✓ In Stock Only" : "Show All (Incl. OOS)"}
                </Link>
                {(category || inStock || sort !== "date_desc") && (
                  <Link
                    href="/products"
                    className="text-xs font-medium px-3.5 py-2.5 rounded-lg border border-rose-900/40 bg-rose-950/10 text-rose-400 hover:bg-rose-950/20 text-center transition-all duration-200"
                  >
                    Reset All Filters
                  </Link>
                )}
              </div>
            </div>
          </aside>

          {/* Catalog grid */}
          <main className="lg:col-span-9 space-y-8">
            {/* Sorting bar */}
            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-zinc-950/40 border border-card-border p-4 rounded-xl">
              <span className="text-xs text-muted font-light">
                Showing {products.length} products
              </span>
              <div className="flex items-center gap-2">
                <span className="text-xs text-muted whitespace-nowrap">Sort by:</span>
                <div className="flex gap-1.5 flex-wrap">
                  {[
                    { key: "date_desc", label: "Newest" },
                    { key: "price_asc", label: "Price: Low to High" },
                    { key: "price_desc", label: "Price: High to Low" },
                    { key: "name_asc", label: "Name" },
                  ].map((s) => (
                    <Link
                      key={s.key}
                      href={`/products?category=${category}&sort=${s.key}&in_stock=${inStock ? "1" : "0"}`}
                      className={`text-xs px-3 py-1.5 rounded-md border transition-all duration-150 ${
                        sort === s.key
                          ? "bg-accent/15 border-accent text-accent font-medium"
                          : "border-card-border bg-card-bg text-muted hover:border-zinc-500"
                      }`}
                    >
                      {s.label}
                    </Link>
                  ))}
                </div>
              </div>
            </div>

            {/* Products grid */}
            {products.length === 0 ? (
              <div className="text-center py-20 bg-zinc-900/10 border border-dashed border-card-border rounded-xl">
                <h3 className="text-xl font-display font-semibold text-white">No products found</h3>
                <p className="text-sm text-muted mt-1">Try adjusting your filters or category selection.</p>
              </div>
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">
                {products.map((product) => (
                  <div key={product.id}>
                    <ProductCard product={product} />
                  </div>
                ))}
              </div>
            )}

            {/* Seek Pagination Navigation */}
            {meta.next_cursor && (
              <div className="flex justify-center pt-4">
                <Link
                  href={`/products?category=${category}&sort=${sort}&in_stock=${inStock ? "1" : "0"}&cursor=${meta.next_cursor}`}
                  className="px-6 py-3 rounded-lg border border-card-border bg-card-bg text-muted hover:border-accent hover:text-accent font-medium text-xs tracking-wider uppercase transition-all duration-200"
                >
                  Next Page →
                </Link>
              </div>
            )}
          </main>
        </div>
      )}
    </div>
  );
}
