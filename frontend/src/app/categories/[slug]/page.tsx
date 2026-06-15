import { notFound } from "next/navigation";
import Link from "next/link";
import { api, Post } from "@/lib/api";

interface CategoryPageProps {
  params: Promise<{ slug: string }>;
}

export default async function CategoryPage({ params }: CategoryPageProps) {
  const { slug } = await params;
  
  // Fetch categories to resolve the category details
  let categories = [];
  try {
    categories = await api.getCategories();
  } catch (e) {
    notFound();
  }

  const category = categories.find((c) => c.slug === slug);
  if (!category) {
    notFound();
  }

  // Fetch posts under this category slug
  let posts: Post[] = [];
  try {
    posts = await api.getPosts(slug);
  } catch (e) {
    // Gracefully fallback to empty list
  }

  return (
    <div className="space-y-10">
      {/* Back Button */}
      <div>
        <Link
          href="/"
          className="inline-flex items-center gap-2 text-sm text-muted hover:text-accent font-semibold transition-colors duration-200"
        >
          <span>←</span> Back to Articles
        </Link>
      </div>

      {/* Category Header */}
      <div className="space-y-2 border-b border-card-border pb-6">
        <span className="text-xs uppercase tracking-wider font-semibold text-accent">
          Category Archive
        </span>
        <h1 className="text-3xl sm:text-5xl font-display font-bold tracking-tight">
          {category.name}
        </h1>
        {category.description && (
          <p className="text-muted text-lg font-light mt-2">{category.description}</p>
        )}
      </div>

      {/* Posts Grid */}
      {posts.length === 0 ? (
        <div className="text-center py-20">
          <h2 className="text-xl font-display font-bold">No articles found</h2>
          <p className="text-muted mt-2">There are currently no published articles in this category.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {posts.map((post) => (
            <article key={post.id} className="glass-card overflow-hidden group flex flex-col justify-between">
              <Link href={`/posts/${post.slug}`} className="block p-6 space-y-3 flex-grow">
                <div className="flex items-center gap-3 text-xs text-muted">
                  <span>
                    {new Date(post.created_at).toLocaleDateString(undefined, {
                      year: "numeric",
                      month: "short",
                      day: "numeric",
                    })}
                  </span>
                  <span className="text-card-border">•</span>
                  <span className="text-accent font-semibold">{category.name}</span>
                </div>
                <h4 className="text-xl font-display font-bold group-hover:text-accent transition-colors duration-200 line-clamp-2">
                  {post.title}
                </h4>
                <p className="text-muted text-sm line-clamp-3 leading-relaxed">
                  {post.excerpt || "Read more details..."}
                </p>
              </Link>
              <div className="px-6 pb-6 pt-0">
                <Link
                  href={`/posts/${post.slug}`}
                  className="inline-flex items-center gap-1.5 text-xs text-accent font-semibold group-hover:gap-3 transition-all duration-200"
                >
                  Read Article <span>→</span>
                </Link>
              </div>
            </article>
          ))}
        </div>
      )}
    </div>
  );
}
