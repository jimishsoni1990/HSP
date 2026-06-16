"use client";

import { useState } from "react";
import { ProductMedia } from "@/lib/api";

interface ProductGalleryProps {
  media?: ProductMedia[];
  featuredImageUrl: string | null;
  productName: string;
}

export default function ProductGallery({ media = [], featuredImageUrl, productName }: ProductGalleryProps) {
  const [selectedIndex, setSelectedIndex] = useState(0);

  // If no media is provided, build a single item array using the featured image URL
  const images = media.length > 0 
    ? [...media].sort((a, b) => a.position - b.position)
    : featuredImageUrl 
      ? [{ id: "featured", url: featuredImageUrl, large_url: featuredImageUrl, thumbnail_url: featuredImageUrl, medium_url: featuredImageUrl, is_featured: true, alt_text: productName, position: 0 }] 
      : [];

  if (images.length === 0) {
    return (
      <div className="w-full aspect-square rounded-xl bg-zinc-900/40 border border-card-border flex items-center justify-center text-muted font-light">
        No Image Available
      </div>
    );
  }

  const currentImg = images[selectedIndex] || images[0];

  return (
    <div className="space-y-4">
      {/* Main image view */}
      <div className="w-full aspect-square rounded-xl overflow-hidden bg-zinc-900/40 border border-card-border relative group">
        <img
          src={currentImg.large_url || currentImg.url}
          alt={currentImg.alt_text || productName}
          className="absolute inset-0 w-full h-full object-cover transition-all duration-300"
        />
        {/* Navigation arrows (only if more than 1 image) */}
        {images.length > 1 && (
          <>
            <button
              onClick={() => setSelectedIndex((prev) => (prev === 0 ? images.length - 1 : prev - 1))}
              className="absolute left-3 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/60 hover:bg-accent hover:text-black border border-white/10 flex items-center justify-center text-white transition-all text-xs"
              aria-label="Previous image"
            >
              ←
            </button>
            <button
              onClick={() => setSelectedIndex((prev) => (prev === images.length - 1 ? 0 : prev + 1))}
              className="absolute right-3 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/60 hover:bg-accent hover:text-black border border-white/10 flex items-center justify-center text-white transition-all text-xs"
              aria-label="Next image"
            >
              →
            </button>
          </>
        )}
      </div>

      {/* Thumbnails strip */}
      {images.length > 1 && (
        <div className="flex gap-2.5 overflow-x-auto pb-1 max-w-full">
          {images.map((img, idx) => {
            const isActive = idx === selectedIndex;
            return (
              <button
                key={img.id || idx}
                onClick={() => setSelectedIndex(idx)}
                className={`relative w-20 aspect-square rounded-lg overflow-hidden flex-shrink-0 bg-zinc-900 border transition-all duration-200 ${
                  isActive ? "border-accent ring-2 ring-accent-glow" : "border-card-border hover:border-zinc-500"
                }`}
              >
                <img
                  src={img.thumbnail_url || img.url}
                  alt={`Thumbnail ${idx + 1}`}
                  className="absolute inset-0 w-full h-full object-cover"
                />
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}
