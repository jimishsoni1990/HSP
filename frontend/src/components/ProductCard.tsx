import Link from "next/link";
import { Product } from "@/lib/api";

interface ProductCardProps {
  product: Product;
}

export default function ProductCard({ product }: ProductCardProps) {
  // Format price display helper
  const formatPrice = (value: string | null) => {
    if (!value) return "";
    const num = parseFloat(value);
    return isNaN(num) ? value : `$${num.toFixed(2)}`;
  };

  const isVariable = product.product_type === "variable";
  const isSale = !isVariable && product.sale_price && product.sale_price !== "";
  
  // Determine pricing text
  let priceDisplay = "";
  if (isVariable) {
    if (product.price_min && product.price_max) {
      if (product.price_min === product.price_max) {
        priceDisplay = formatPrice(product.price_min);
      } else {
        priceDisplay = `${formatPrice(product.price_min)} - ${formatPrice(product.price_max)}`;
      }
    } else {
      priceDisplay = "From " + formatPrice(product.price);
    }
  } else {
    priceDisplay = formatPrice(product.price);
  }

  // Stock status styles and text
  const stockConfig = {
    instock: { text: "In Stock", class: "bg-emerald-950/20 text-emerald-400 border-emerald-900/40" },
    outofstock: { text: "Out of Stock", class: "bg-rose-950/20 text-rose-400 border-rose-900/40" },
    onbackorder: { text: "On Backorder", class: "bg-amber-950/20 text-amber-400 border-amber-900/40" },
  };
  const stock = stockConfig[product.stock_status] || { text: product.stock_status, class: "bg-zinc-950/20 text-zinc-400 border-zinc-900/40" };

  // Product type badge
  const typeLabels = {
    simple: "Simple Product",
    variable: "Variable Product",
    grouped: "Grouped Product",
    external: "External Product",
  };
  const typeText = typeLabels[product.product_type] || product.product_type;

  return (
    <article 
      id={`product-card-${product.id}`}
      className="glass-card overflow-hidden group flex flex-col h-full"
    >
      <Link href={`/products/${product.slug}`} className="block relative h-64 overflow-hidden bg-zinc-900/40">
        {product.featured_image_url ? (
          <img
            src={product.featured_image_url}
            alt={product.name}
            className="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
          />
        ) : (
          <div className="absolute inset-0 flex items-center justify-center text-muted font-light text-sm">
            No Image Available
          </div>
        )}
        <div className="absolute top-3 right-3 flex flex-col gap-1.5 items-end">
          <span className="text-[10px] uppercase font-semibold px-2 py-0.5 rounded bg-black/60 backdrop-blur-md text-white border border-white/10">
            {typeText}
          </span>
          <span className={`text-[10px] uppercase font-semibold px-2 py-0.5 rounded border ${stock.class}`}>
            {stock.text}
          </span>
        </div>
      </Link>

      <div className="p-5 flex flex-col flex-grow justify-between space-y-4">
        <div className="space-y-1.5">
          <h3 className="font-display font-semibold text-lg leading-snug tracking-tight text-white group-hover:text-accent transition-colors duration-200">
            <Link href={`/products/${product.slug}`}>
              {product.name}
            </Link>
          </h3>
          <p className="text-sm text-muted font-light line-clamp-2">
            {product.short_description ? product.short_description.replace(/<[^>]*>/g, '') : "View details to check product information."}
          </p>
        </div>

        <div className="flex items-center justify-between pt-2">
          <div className="flex flex-col">
            {isSale ? (
              <div className="flex items-baseline gap-2">
                <span className="text-lg font-bold text-accent">{priceDisplay}</span>
                <span className="text-xs text-muted line-through">{formatPrice(product.regular_price)}</span>
              </div>
            ) : (
              <span className="text-lg font-bold text-white">{priceDisplay}</span>
            )}
          </div>
          <Link 
            href={`/products/${product.slug}`}
            className="text-xs glow-btn px-4 py-2"
          >
            {product.product_type === "external" && product.button_text ? product.button_text : "View Product"}
          </Link>
        </div>
      </div>
    </article>
  );
}
