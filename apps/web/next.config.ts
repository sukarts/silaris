import path from "node:path";
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  reactStrictMode: true,
  // Sortie autonome pour l'image Docker de production (server.js + deps minimales).
  output: "standalone",
  // Monorepo : la racine de traçage des fichiers remonte au workspace.
  outputFileTracingRoot: path.join(__dirname, "../../"),
};

export default nextConfig;
