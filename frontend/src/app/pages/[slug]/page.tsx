import { notFound } from "next/navigation";
import Link from "next/link";
import { api } from "@/lib/api";

interface PageProps {
  params: Promise<{ slug: string }>;
}

export default async function StaticPage({ params }: PageProps) {
  const { slug } = await params;
  const page = await api.getPageBySlug(slug);

  if (!page) {
    notFound();
  }

  return (
    <article className="space-y-8 max-w-3xl mx-auto">
      {/* Back Button */}
      <div>
        <Link
          href="/"
          className="inline-flex items-center gap-2 text-sm text-muted hover:text-accent font-semibold transition-colors duration-200"
        >
          <span>←</span> Back to Articles
        </Link>
      </div>

      {/* Page Header */}
      <div className="space-y-2 border-b border-card-border pb-6">
        <h1 className="text-3xl sm:text-5xl font-display font-bold tracking-tight">
          {page.title}
        </h1>
        <p className="text-xs text-muted">
          Last updated:{" "}
          {new Date(page.updated_at).toLocaleDateString(undefined, {
            year: "numeric",
            month: "long",
            day: "numeric",
          })}
        </p>
      </div>

      {/* Page Body Content */}
      <div 
        className="prose prose-invert max-w-none text-foreground/90 leading-relaxed space-y-6 
                   [&_h2]:text-2xl [&_h2]:font-display [&_h2]:font-bold [&_h2]:pt-6 [&_h2]:text-accent
                   [&_h3]:text-xl [&_h3]:font-display [&_h3]:font-bold [&_h3]:pt-4
                   [&_p]:leading-relaxed [&_p]:text-base
                   [&_ul]:list-disc [&_ul]:pl-6 [&_ul]:space-y-2
                   [&_ol]:list-decimal [&_ol]:pl-6 [&_ol]:space-y-2
                   [&_a]:text-accent [&_a]:underline [&_a]:underline-offset-4 [&_a:hover]:opacity-80"
        dangerouslySetInnerHTML={{ __html: page.content ?? "" }} // Fallback if content is null
      />
    </article>
  );
}
