import { notFound } from "next/navigation";
import Link from "next/link";
import { api } from "@/lib/api";
import type { Metadata } from "next";

interface PostPageProps {
  params: Promise<{ slug: string }>;
}

export async function generateMetadata({ params }: PostPageProps): Promise<Metadata> {
  const { slug } = await params;
  const post = await api.getPostBySlug(slug);

  if (!post) {
    return {
      title: "Post Not Found",
    };
  }

  const seo = post.seo;

  return {
    title: seo?.meta_title || post.title,
    description: seo?.meta_description || post.excerpt || undefined,
    openGraph: {
      title: seo?.og_title || seo?.meta_title || post.title,
      description: seo?.og_description || seo?.meta_description || post.excerpt || undefined,
      images: seo?.og_image ? [{ url: seo.og_image }] : [],
      type: "article",
    },
  };
}

export default async function PostPage({ params }: PostPageProps) {
  const { slug } = await params;
  const post = await api.getPostBySlug(slug);

  if (!post) {
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

      {/* Meta Info Header */}
      <div className="space-y-4">
        <div className="flex flex-wrap items-center gap-3 text-sm text-muted">
          <span>
            {new Date(post.created_at).toLocaleDateString(undefined, {
              year: "numeric",
              month: "long",
              day: "numeric",
            })}
          </span>
          {post.categories && post.categories.length > 0 && (
            <>
              <span className="text-card-border">•</span>
              <div className="flex gap-2">
                {post.categories.map((cat) => (
                  <Link
                    key={cat.id}
                    href={`/category/${cat.slug}`}
                    className="text-xs font-semibold px-2.5 py-1 rounded-full bg-card-bg border border-card-border text-accent hover:border-accent transition-all duration-200"
                  >
                    {cat.name}
                  </Link>
                ))}
              </div>
            </>
          )}
        </div>

        <h1 className="text-3xl sm:text-5xl font-display font-bold tracking-tight leading-tight">
          {post.title}
        </h1>
      </div>

      {/* Article Excerpt */}
      {post.excerpt && (
        <p className="text-lg text-muted font-light leading-relaxed border-l-2 border-accent pl-4 italic">
          {post.excerpt}
        </p>
      )}

      {/* Article Content */}
      <div 
        className="prose prose-invert max-w-none text-foreground/90 leading-relaxed space-y-6 
                   [&_h2]:text-2xl [&_h2]:font-display [&_h2]:font-bold [&_h2]:pt-6 [&_h2]:text-accent
                   [&_h3]:text-xl [&_h3]:font-display [&_h3]:font-bold [&_h3]:pt-4
                   [&_p]:leading-relaxed [&_p]:text-base
                   [&_ul]:list-disc [&_ul]:pl-6 [&_ul]:space-y-2
                   [&_ol]:list-decimal [&_ol]:pl-6 [&_ol]:space-y-2
                   [&_pre]:bg-card-bg [&_pre]:p-4 [&_pre]:rounded-lg [&_pre]:border [&_pre]:border-card-border [&_pre]:overflow-x-auto
                   [&_code]:font-mono [&_code]:text-sm [&_code]:bg-card-bg [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:rounded [&_code]:border [&_code]:border-card-border
                   [&_a]:text-accent [&_a]:underline [&_a]:underline-offset-4 [&_a:hover]:opacity-80
                   [&_blockquote]:border-l-4 [&_blockquote]:border-accent-glow [&_blockquote]:pl-4 [&_blockquote]:italic"
        dangerouslySetInnerHTML={{ __html: post.content }}
      />
    </article>
  );
}
