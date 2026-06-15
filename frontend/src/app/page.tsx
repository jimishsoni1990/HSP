import Link from "next/link";
import { api, Post, Category } from "@/lib/api";

export default async function Home() {
  let posts: Post[] = [];
  let categories: Category[] = [];
  let errorMsg: string | null = null;

  try {
    // Fetch posts and categories in parallel for maximum speed
    const [fetchedPosts, fetchedCategories] = await Promise.all([
      api.getPosts(),
      api.getCategories(),
    ]);
    posts = fetchedPosts;
    categories = fetchedCategories;
  } catch (e: any) {
    errorMsg = e.message || "Failed to load blog content.";
  }

  if (errorMsg) {
    return (
      <div className="text-center py-20">
        <div className="inline-block p-6 rounded-lg bg-red-950/20 border border-red-900/40 text-red-400">
          <p className="font-semibold">Error Loading Content</p>
          <p className="text-sm mt-1">{errorMsg}</p>
        </div>
      </div>
    );
  }

  if (posts.length === 0) {
    return (
      <div className="text-center py-20">
        <h2 className="text-2xl font-display font-bold">No posts found</h2>
        <p className="text-muted mt-2">Publish your first article in WordPress to sync it here.</p>
      </div>
    );
  }

  const featuredPost = posts[0];
  const gridPosts = posts.slice(1);

  return (
    <div className="space-y-12">
      {/* Page Heading */}
      <div className="space-y-2">
        <h1 className="text-4xl sm:text-5xl font-display font-bold tracking-tight">
          Headless <span className="text-accent">Insights</span>
        </h1>
        <p className="text-muted text-lg font-light">
          Real-time updates directly projected from PostgreSQL.
        </p>
      </div>

      {/* Category Filter Bar */}
      {categories.length > 0 && (
        <div className="flex flex-wrap gap-2 py-4 border-y border-card-border overflow-x-auto">
          <span className="text-xs uppercase tracking-wider font-semibold text-muted flex items-center mr-2">
            Categories:
          </span>
          <Link
            href="/"
            className="text-xs font-semibold px-3 py-1.5 rounded-full bg-accent text-black transition-colors duration-200"
          >
            All Posts
          </Link>
          {categories.map((cat) => (
            <Link
              key={cat.id}
              href={`/categories/${cat.slug}`}
              className="text-xs font-semibold px-3 py-1.5 rounded-full bg-card-bg border border-card-border text-muted hover:border-accent hover:text-accent transition-all duration-200"
            >
              {cat.name}
            </Link>
          ))}
        </div>
      )}

      {/* Featured Post Card */}
      {featuredPost && (
        <div className="glass-card overflow-hidden group">
          <Link href={`/posts/${featuredPost.slug}`} className="block">
            <div className="p-6 sm:p-10 space-y-4">
              <div className="flex items-center gap-4 text-xs text-muted">
                <span>
                  {new Date(featuredPost.created_at).toLocaleDateString(undefined, {
                    year: "numeric",
                    month: "long",
                    day: "numeric",
                  })}
                </span>
                {featuredPost.categories && featuredPost.categories.length > 0 && (
                  <>
                    <span className="text-card-border">•</span>
                    <span className="text-accent font-semibold">
                      {featuredPost.categories[0].name}
                    </span>
                  </>
                )}
              </div>
              <h2 className="text-2xl sm:text-4xl font-display font-bold group-hover:text-accent transition-colors duration-200">
                {featuredPost.title}
              </h2>
              <p className="text-muted leading-relaxed text-sm sm:text-base line-clamp-3">
                {featuredPost.excerpt || "Read the full article..."}
              </p>
              <div className="pt-4 flex items-center gap-2 text-accent font-semibold text-sm group-hover:gap-4 transition-all duration-200">
                Read Article <span>→</span>
              </div>
            </div>
          </Link>
        </div>
      )}

      {/* Remaining Posts Grid */}
      {gridPosts.length > 0 && (
        <div className="space-y-6">
          <h3 className="text-xl font-display font-bold">Latest Articles</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            {gridPosts.map((post) => (
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
                    {post.categories && post.categories.length > 0 && (
                      <>
                        <span className="text-card-border">•</span>
                        <span className="text-accent font-semibold">
                          {post.categories[0].name}
                        </span>
                      </>
                    )}
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
        </div>
      )}
    </div>
  );
}
