/**
 * Toporia Vite Plugin
 *
 * Custom Vite plugin for Toporia Framework.
 * Handles manifest generation and asset management.
 *
 * Features:
 * - Manifest generation for production builds
 * - Asset path resolution
 * - CSS file association
 * - Development server integration
 */

import { writeFileSync, mkdirSync } from 'fs';
import { resolve, dirname, relative } from 'path';

export default function toporiaVitePlugin(options = {}) {
  const {
    input = [],
    manifestPath = 'public/build/.vite/manifest.json',
    publicDir = 'public',
  } = options;

  let config;
  let buildOutDir;

  return {
    name: 'toporia-vite-plugin',
    configResolved(resolvedConfig) {
      config = resolvedConfig;
      buildOutDir = resolvedConfig.build?.outDir || 'dist';
    },
    buildStart() {
      // Ensure manifest directory exists
      const manifestDir = dirname(manifestPath);
      try {
        mkdirSync(manifestDir, { recursive: true });
      } catch (e) {
        // Directory might already exist
      }
    },
    writeBundle(options, bundle) {
      // Generate manifest after all files are written
      if (config.command === 'build') {
        const manifest = {};
        const outDir = resolve(process.cwd(), buildOutDir);

        // Map input entries to their output files
        const entryMap = new Map();

        // First pass: Map entry points to their output chunks
        for (const [fileName, chunk] of Object.entries(bundle)) {
          if (chunk.isEntry && chunk.type === 'chunk') {
            // Find matching input entry by checking chunk facadeModuleId or moduleIds
            const entry = input.find(entryPath => {
              const entryName = entryPath.replace(/^.*\//, '').replace(/\.(js|ts|jsx|tsx)$/, '');
              return chunk.name === entryName ||
                chunk.facadeModuleId?.includes(entryPath) ||
                chunk.moduleIds?.some(id => id.includes(entryPath));
            });

            if (entry) {
              // Get relative path from outDir
              const relativePath = relative(outDir, resolve(outDir, fileName));
              entryMap.set(entry, {
                file: relativePath.replace(/\\/g, '/'), // Normalize path separators
                chunk: chunk,
              });
            }
          }
        }

        // Second pass: Build manifest with CSS associations
        for (const [entry, entryData] of entryMap.entries()) {
          const cssFiles = [];

          // Find CSS files associated with this entry
          // CSS files are usually named similarly or have imports from the entry
          for (const [fileName, asset] of Object.entries(bundle)) {
            if (asset.type === 'asset' && fileName.endsWith('.css')) {
              // Check if CSS is imported by this entry chunk
              const entryName = entry.replace(/^.*\//, '').replace(/\.(js|ts|jsx|tsx)$/, '');
              if (fileName.includes(entryName) ||
                asset.name?.includes(entryName) ||
                entryData.chunk.imports?.some(imp => fileName.includes(imp))) {
                const relativePath = relative(outDir, resolve(outDir, fileName));
                cssFiles.push(relativePath.replace(/\\/g, '/'));
              }
            }
          }

          manifest[entry] = {
            file: entryData.file,
            src: entry,
            isEntry: true,
            css: cssFiles,
          };
        }

        // Also handle CSS-only entries
        for (const entry of input) {
          if (entry.endsWith('.css') && !manifest[entry]) {
            // Find CSS asset
            for (const [fileName, asset] of Object.entries(bundle)) {
              if (asset.type === 'asset' && fileName.endsWith('.css')) {
                const entryName = entry.replace(/^.*\//, '').replace(/\.css$/, '');
                if (fileName.includes(entryName)) {
                  const relativePath = relative(outDir, resolve(outDir, fileName));
                  manifest[entry] = {
                    file: relativePath.replace(/\\/g, '/'),
                    src: entry,
                    isEntry: true,
                    css: [],
                  };
                  break;
                }
              }
            }
          }
        }

        // Write manifest file
        const manifestFile = resolve(process.cwd(), manifestPath);
        writeFileSync(manifestFile, JSON.stringify(manifest, null, 2));
      }
    },
  };
}

