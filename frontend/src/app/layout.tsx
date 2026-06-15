import type { Metadata } from "next";
import Link from "next/link";
import { api, Category } from "@/lib/api";
import "./globals.css";

export const metadata: Metadata = {
  title: "HSP Headless Blog",
  description: "Next-generation high-performance blog powered by WordPress outbox sync and PostgreSQL projections.",
};

export default async function RootLayout({
  children,
  headerCategoriesSlot,
}: Readonly<{
  children: React.ReactNode;
  headerCategoriesSlot?: React.ReactNode;
}>) {
  // Fetch active categories for the top navigation bar
  let categories: Category[] = [];
  try {
    categories = await api.getCategories();
  } catch (e) {
    // Graceful fallback if database/API is offline during build
  }

  return (
    <html lang="en" className="h-full">
      <body className="min-h-full flex flex-col bg-background text-foreground font-sans antialiased selection:bg-accent selection:text-black">
        {/* Global Navigation Header */}
        <header className="sticky top-0 z-50 backdrop-blur-md bg-background/80 border-b border-card-border">
          <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div className="flex items-center justify-between h-16">
              {/* Logo / Home link */}
              <div className="flex-shrink-0">
                <Link href="/" className="flex items-center gap-2 group">
                  <div className="w-8 h-8 rounded-lg bg-accent flex items-center justify-center text-black font-display font-bold shadow-md group-hover:scale-105 transition-transform duration-200">
                    H
                  </div>
                  <span className="font-display font-bold text-lg tracking-tight hover:text-accent transition-colors duration-200">
                    HSP <span className="text-accent">Blog</span>
                  </span>
                </Link>
              </div>

              {/* Navigation Menu */}
              <nav className="flex items-center gap-6">
                <Link
                  href="/"
                  className="text-sm font-medium hover:text-accent transition-colors duration-200"
                >
                  Home
                </Link>
                
                {/* Category Links in Navbar */}
                {categories.length > 0 && (
                  <div className="hidden md:flex items-center gap-6">
                    {categories.slice(0, 3).map((cat) => (
                      <Link
                        key={cat.id}
                        href={`/category/${cat.slug}`}
                        className="text-sm font-medium text-muted hover:text-accent transition-colors duration-200"
                      >
                        {cat.name}
                      </Link>
                    ))}
                  </div>
                )}

                <Link
                  href="/pages/about"
                  className="text-sm font-medium text-muted hover:text-accent transition-colors duration-200"
                >
                  About
                </Link>
              </nav>
            </div>
          </div>
        </header>

        {/* Page Content */}
        <main className="flex-grow max-w-6xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-10">
          {children}
        </main>

        {/* Footer */}
        <footer className="border-t border-card-border bg-card-bg/20 py-8 mt-20">
          <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-4">
            <div className="text-sm text-muted">
              © {new Date().getFullYear()} HSP Blog. All rights reserved.
            </div>
            <div className="text-xs text-muted flex gap-4">
              <span>Powered by Next.js & PostgreSQL</span>
              <span className="text-accent">•</span>
              <span>Headless WordPress Sync</span>
            </div>
          </div>
        </footer>
      </body>
    </html>
  );
}
