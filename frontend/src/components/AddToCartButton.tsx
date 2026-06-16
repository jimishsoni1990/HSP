import { Product, ProductVariation } from "@/lib/api";

interface AddToCartButtonProps {
  product: Product;
  matchedVariation: ProductVariation | null;
  selectedAttributes: Record<string, string>;
}

export default function AddToCartButton({ product, matchedVariation, selectedAttributes }: AddToCartButtonProps) {
  const storeUrl = process.env.NEXT_PUBLIC_WC_STORE_URL || "http://localhost:8080";
  const isExternal = product.product_type === "external";
  const isVariable = product.product_type === "variable";

  // Check variation-driving attributes count
  const variationDrivingAttrsCount = product.attributes
    ? product.attributes.filter(a => a.is_for_variations).length
    : 0;

  // Requirements met?
  const hasOptionsSelected = !isVariable || (matchedVariation !== null);
  const isOutOfStock = product.stock_status === "outofstock" || 
    (isVariable && matchedVariation && matchedVariation.stock_status === "outofstock");

  // Determine redirection link
  let cartLink = "";
  if (isExternal) {
    cartLink = product.external_url || "#";
  } else if (isVariable && matchedVariation) {
    cartLink = `${storeUrl}/?add-to-cart=${product.source_post_id}&variation_id=${matchedVariation.source_variation_id}&quantity=1`;
  } else {
    cartLink = `${storeUrl}/?add-to-cart=${product.source_post_id}&quantity=1`;
  }

  // Determine CTA text
  let btnText = "Add to Cart";
  if (isExternal) {
    btnText = product.button_text || "Buy Product";
  } else if (isOutOfStock) {
    btnText = "Out of Stock";
  } else if (isVariable && !matchedVariation) {
    btnText = "Select Options";
  }

  const isDisabled = isOutOfStock || (isVariable && !matchedVariation);

  return (
    <div className="space-y-2">
      {isDisabled ? (
        <button
          disabled
          className="w-full text-center py-3.5 bg-zinc-800 text-zinc-500 border border-zinc-700/40 rounded-lg cursor-not-allowed font-medium text-sm transition-all duration-200"
        >
          {btnText}
        </button>
      ) : (
        <a
          href={cartLink}
          target={isExternal ? "_blank" : "_self"}
          rel={isExternal ? "noopener noreferrer" : ""}
          className="w-full inline-block text-center py-3.5 glow-btn text-black font-semibold text-sm transition-all duration-200"
        >
          {btnText}
        </a>
      )}

      {isVariable && !matchedVariation && variationDrivingAttrsCount > 0 && (
        <p className="text-xs text-muted text-center font-light italic">
          * Please select variations above to buy this product.
        </p>
      )}
    </div>
  );
}
