"use client";

import { useState } from "react";
import { Product, ProductVariation } from "@/lib/api";
import ProductAttributeSelector from "./ProductAttributeSelector";
import AddToCartButton from "./AddToCartButton";

interface ProductDetailPanelProps {
  product: Product;
}

export default function ProductDetailPanel({ product }: ProductDetailPanelProps) {
  const [selectedAttributes, setSelectedAttributes] = useState<Record<string, string>>({});
  const [matchedVariation, setMatchedVariation] = useState<ProductVariation | null>(null);

  // Format price display helper
  const formatPrice = (value: string | null) => {
    if (!value) return "";
    const num = parseFloat(value);
    return isNaN(num) ? value : `$${num.toFixed(2)}`;
  };

  const isVariable = product.product_type === "variable";
  
  // Resolve active price to display
  let priceDisplay = "";
  let regularPriceDisplay = "";
  let isSale = false;

  if (isVariable && matchedVariation) {
    priceDisplay = formatPrice(matchedVariation.price);
    if (matchedVariation.sale_price && matchedVariation.sale_price !== "") {
      regularPriceDisplay = formatPrice(matchedVariation.regular_price);
      isSale = true;
    }
  } else if (isVariable) {
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
    if (product.sale_price && product.sale_price !== "") {
      regularPriceDisplay = formatPrice(product.regular_price);
      isSale = true;
    }
  }

  // Resolve active stock status
  const activeStockStatus = isVariable && matchedVariation 
    ? matchedVariation.stock_status 
    : product.stock_status;

  const activeStockQty = isVariable && matchedVariation 
    ? matchedVariation.stock_quantity 
    : product.stock_quantity;

  const stockConfig = {
    instock: { text: "In Stock", class: "bg-emerald-950/20 text-emerald-400 border-emerald-900/40" },
    outofstock: { text: "Out of Stock", class: "bg-rose-950/20 text-rose-400 border-rose-900/40" },
    onbackorder: { text: "On Backorder", class: "bg-amber-950/20 text-amber-400 border-amber-900/40" },
  };
  const stock = stockConfig[activeStockStatus] || { text: activeStockStatus, class: "bg-zinc-950/20 text-zinc-400 border-zinc-900/40" };

  // Resolve active SKU
  const activeSku = isVariable && matchedVariation && matchedVariation.sku
    ? matchedVariation.sku
    : product.sku;

  return (
    <div className="space-y-6">
      {/* Price & Stock info bar */}
      <div className="flex flex-wrap items-baseline gap-4">
        {isSale ? (
          <div className="flex items-baseline gap-3">
            <span className="text-3xl font-bold text-accent">{priceDisplay}</span>
            <span className="text-sm text-muted line-through">{regularPriceDisplay}</span>
          </div>
        ) : (
          <span className="text-3xl font-bold text-white">{priceDisplay}</span>
        )}
        
        <span className={`text-xs uppercase font-semibold px-2.5 py-1.5 rounded border ${stock.class}`}>
          {stock.text} {activeStockQty !== null ? `(${activeStockQty} left)` : ""}
        </span>
      </div>

      {/* Variation attributes selectors */}
      {isVariable && product.attributes && product.variations && (
        <ProductAttributeSelector
          attributes={product.attributes}
          variations={product.variations}
          onVariationMatch={(variation, selected) => {
            setMatchedVariation(variation);
            setSelectedAttributes(selected);
          }}
        />
      )}

      {/* Add To Cart action */}
      <div className="pt-2">
        <AddToCartButton
          product={product}
          matchedVariation={matchedVariation}
          selectedAttributes={selectedAttributes}
        />
      </div>

      {/* SKU & Technical Specs */}
      <div className="pt-4 border-t border-card-border space-y-2 text-xs text-muted font-light">
        {activeSku && (
          <div className="flex justify-between">
            <span>SKU:</span>
            <span className="font-medium text-white">{activeSku}</span>
          </div>
        )}
        {product.weight && (
          <div className="flex justify-between">
            <span>Weight:</span>
            <span className="font-medium text-white">{parseFloat(product.weight).toFixed(2)} kg</span>
          </div>
        )}
        {product.dimensions && (product.dimensions.length || product.dimensions.width || product.dimensions.height) && (
          <div className="flex justify-between">
            <span>Dimensions:</span>
            <span className="font-medium text-white">
              {product.dimensions.length} × {product.dimensions.width} × {product.dimensions.height} cm
            </span>
          </div>
        )}
      </div>
    </div>
  );
}
