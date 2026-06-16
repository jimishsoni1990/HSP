"use client";

import { useState, useEffect } from "react";
import { ProductAttribute, ProductVariation } from "@/lib/api";

interface ProductAttributeSelectorProps {
  attributes: ProductAttribute[];
  variations: ProductVariation[];
  onVariationMatch: (variation: ProductVariation | null, selected: Record<string, string>) => void;
}

export default function ProductAttributeSelector({
  attributes,
  variations,
  onVariationMatch,
}: ProductAttributeSelectorProps) {
  // Extract only attributes that drive variations
  const variationAttributes = attributes
    .filter((attr) => attr.is_for_variations)
    .sort((a, b) => a.position - b.position);

  const [selectedAttributes, setSelectedAttributes] = useState<Record<string, string>>({});

  // Trigger matching logic on attribute selection change
  useEffect(() => {
    // Check if we have selections for all variation attributes
    const allSelected = variationAttributes.every((attr) => !!selectedAttributes[attr.attribute_key]);

    if (allSelected && variationAttributes.length > 0) {
      // Find a variation that matches the selected attributes subset
      const match = variations.find((v) => {
        return Object.entries(v.attributes).every(([key, val]) => {
          // WC variations attributes key might be taxonomy e.g. 'pa_color'
          return selectedAttributes[key] === val;
        });
      });
      onVariationMatch(match ?? null, selectedAttributes);
    } else {
      onVariationMatch(null, selectedAttributes);
    }
  }, [selectedAttributes, variations]);

  // Handle option selection
  const handleSelect = (key: string, val: string) => {
    setSelectedAttributes((prev) => {
      const copy = { ...prev };
      if (copy[key] === val) {
        delete copy[key]; // toggle selection off
      } else {
        copy[key] = val;
      }
      return copy;
    });
  };

  // Helper to determine if an option value is available given current selections of other attributes
  const isOptionAvailable = (attrKey: string, value: string): boolean => {
    // Temp selections incorporating the candidate option value
    const nextSelections = { ...selectedAttributes, [attrKey]: value };

    // Check if there is at least one enabled variation that matches all current selections + the candidate
    return variations.some((v) => {
      if (!v.is_enabled) return false;
      return Object.entries(nextSelections).every(([selKey, selVal]) => {
        // If the variation has a matching attribute or if it's set to "any value" (empty string)
        return !v.attributes[selKey] || v.attributes[selKey] === selVal;
      });
    });
  };

  if (variationAttributes.length === 0) {
    return null;
  }

  return (
    <div className="space-y-4 py-4 border-y border-card-border">
      {variationAttributes.map((attr) => {
        const currentSelection = selectedAttributes[attr.attribute_key] || null;
        return (
          <div key={attr.id} className="space-y-2">
            <span className="text-xs font-semibold uppercase tracking-wider text-muted">
              Choose {attr.attribute_label}:
            </span>
            <div className="flex flex-wrap gap-2">
              {attr.values.map((val) => {
                const isSelected = currentSelection === val;
                const isAvailable = isOptionAvailable(attr.attribute_key, val);

                return (
                  <button
                    key={val}
                    onClick={() => isAvailable && handleSelect(attr.attribute_key, val)}
                    disabled={!isAvailable && !isSelected}
                    className={`text-xs px-3.5 py-2 rounded-lg border font-medium transition-all duration-200 ${
                      isSelected
                        ? "bg-accent border-accent text-black font-semibold"
                        : isAvailable
                        ? "bg-card-bg border-card-border text-foreground hover:border-zinc-400"
                        : "bg-zinc-950/20 border-zinc-900/40 text-zinc-600 cursor-not-allowed opacity-40 line-through"
                    }`}
                  >
                    {val}
                  </button>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}
