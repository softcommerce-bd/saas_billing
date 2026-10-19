import type { NextConfig } from "next";

// The browser always talks to a single origin. In development Next.js proxies
// /api and /sanctum to Laravel; in production nginx does the same routing, so
// Sanctum's session cookie stays first-party and no CORS is needed.
const apiOrigin = process.env.API_ORIGIN ?? "http://127.0.0.1:8000";

const securityHeaders = [
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
];

const nextConfig: NextConfig = {
  cacheComponents: true,
  partialPrefetching: true,
  poweredByHeader: false,
  // Self-contained server bundle for the Docker image.
  output: "standalone",
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
  async rewrites() {
    return [
      { source: "/api/:path*", destination: `${apiOrigin}/api/:path*` },
      { source: "/sanctum/:path*", destination: `${apiOrigin}/sanctum/:path*` },
      { source: "/dev/payment-simulator/:path*", destination: `${apiOrigin}/dev/payment-simulator/:path*` },
    ];
  },
  async headers() {
    return [{ source: "/:path*", headers: securityHeaders }];
  },
};

export default nextConfig;
