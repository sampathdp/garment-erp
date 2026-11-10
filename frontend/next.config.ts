import type { NextConfig } from "next";

/**
 * Static export: the app is plain HTML/JS/CSS so shared hosting can serve it (ARCHITECTURE.md §5).
 * This rules out Proxy (middleware), Server Actions, route handlers, rewrites/redirects/headers
 * and dynamic segments without generateStaticParams. Record pages use query params (?id=).
 * Security headers (CSP etc.) are set by the web server (.htaccess), see public/.htaccess.
 */
const nextConfig: NextConfig = {
  output: "export",
  trailingSlash: true,
  images: { unoptimized: true },
  poweredByHeader: false,
  reactStrictMode: true,
  // Cache Components / Partial Prefetching (PPR) need a Next.js server: "PPR cannot be enabled in export mode".
  // Re-enable only if the frontend moves to a Node host (profile B).
  cacheComponents: false,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
