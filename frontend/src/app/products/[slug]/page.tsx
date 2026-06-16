import { Metadata } from "next";
import { notFound } from "next/navigation";
import Link from "next/link";
import { api } from "@/lib/api";
import ProductGallery from "@/components/ProductGallery";
import ProductDetailPanel from "@/components/ProductDetailPanel";

export const revalidate = 300; // PDP revalidate every 5 min (ISR)

interface PageProps {
  params: Promise<{ slug: string }>;
}

// Pre-generate dynamic routes for the top 50 products
export async function generateStaticParams() {
  try {
    const response = await api.getProducts({ per_page: 50 });
    return response.data.map((product) => ({
      slug: product.slug,
    }));
  } catch (e) {
    return [];
  }
}

// Generate dynamic metadata for SEO crawling
export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const resolvedParams = await params;
  const product = await api.getProductBySlug(resolvedParams.slug);

  if (!product) {
    return { title: "Product Not Found" };
  }

  return {
    title: product.seo?.meta_title || `${product.name} | HSP Store`,
    description: product.seo?.meta_description || product.short_description || `Purchase ${product.name} at HSP Store.`,
    openGraph: {
      title: product.seo?.og_title || product.name,
      description: product.seo?.og_description || product.short_description,
      images: product.featured_image_url ? [{ url: product.featured_image_url }] : [],
      type: "website",
    },
  };
}

export default async function ProductDetailPage({ params }: PageProps) {
  const resolvedParams = await params;
  const product = await api.getProductBySlug(resolvedParams.slug);

  if (!product) {
    notFound();
  }

  // Find non-variation specifications attributes
  const specAttributes = product.attributes
    ? product.attributes.filter(attr => !attr.is_for_variations && attr.is_visible)
    : [];

  return (
    <article id={`product-detail-${product.id}`} className="space-y-12">
      {/* Breadcrumbs */}
      <nav className="text-xs text-muted font-light flex gap-2 items-center">
        <Link href="/products" className="hover:text-accent transition-colors duration-150">
          Products
        </Link>
        <span>/</span>
        {product.categories && product.categories.length > 0 && (
          <>
            <Link 
              href={`/products?category=${product.categories[0].slug}`} 
              className="hover:text-accent transition-colors duration-150"
            >
              {product.categories[0].name}
            </Link>
            <span>/</span>
          </>
        )}
        <span className="text-white truncate max-w-[200px]">{product.name}</span>
      </nav>

      {/* Two-column Main View */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-8 lg:gap-12">
        {/* Left Column - Gallery */}
        <div>
          <ProductGallery
            media={product.media}
            featuredImageUrl={product.featured_image_url}
            productName={product.name}
          />
        </div>

        {/* Right Column - Product Options & Panel */}
        <div className="space-y-6">
          <div className="space-y-2">
            <span className="text-[10px] uppercase font-semibold px-2 py-0.5 rounded bg-zinc-900 border border-card-border text-muted">
              {product.product_type} Product
            </span>
            <h1 className="text-3xl sm:text-4xl font-display font-bold tracking-tight text-white leading-tight">
              {product.name}
            </h1>
          </div>

          {product.short_description && (
            <p className="text-sm text-muted font-light leading-relaxed">
              {product.short_description.replace(/<[^>]*>/g, '')}
            </p>
          )}

          {/* Interactive options, pricing, attributes, and AddToCart button */}
          <ProductDetailPanel product={product} />
        </div>
      </div>

      {/* Full HTML Description & Specifications */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 pt-8 border-t border-card-border">
        {/* HTML Content (dangerouslySetInnerHTML for WP editor support) */}
        <div className="lg:col-span-8 space-y-4">
          <h2 className="font-display font-bold text-xl text-white">
            Product Description
          </h2>
          <div 
            className="prose prose-invert max-w-none text-sm text-muted font-light leading-relaxed space-y-4"
            dangerouslySetInnerHTML={{ __html: product.description || "<p>No description available.</p>" }}
          />
        </div>

        {/* Non-variation specifications */}
        {specAttributes.length > 0 && (
          <div className="lg:col-span-4 space-y-4">
            <h2 className="font-display font-bold text-xl text-white">
              Specifications
            </h2>
            <div className="bg-zinc-950/40 border border-card-border rounded-xl p-4 overflow-hidden">
              <table className="w-full text-xs text-left border-collapse">
                <tbody>
                  {specAttributes.map((attr) => (
                    <tr key={attr.id} className="border-b border-card-border last:border-0">
                      <td className="py-2.5 font-semibold text-muted w-1/3 pr-2">
                        {attr.attribute_label}
                      </td>
                      <td className="py-2.5 text-white font-light">
                        {attr.values.join(", ")}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>
    </article>
  );
}
