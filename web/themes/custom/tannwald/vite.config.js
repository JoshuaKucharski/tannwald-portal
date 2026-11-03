// Vite-Konfiguration des Themes: zwei Eingaben (CSS, JS), feste Dateinamen in dist/ ohne Hash,
// kein Manifest, damit tannwald.libraries.yml stabile Pfade referenzieren kann. Webfont-Dateien
// (@fontsource/inter, aus src/tailwind.css referenziert) landen unter dist/fonts/ mit festem Namen;
// base './' schreibt die URLs im CSS relativ zur CSS-Datei (Aufgabe 1.1).
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  base: './',
  plugins: [tailwindcss()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: false,
    cssCodeSplit: true,
    sourcemap: false,
    assetsInlineLimit: 0,
    rollupOptions: {
      input: {
        app: 'src/app.js',
        tailwind: 'src/tailwind.css',
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: '[name].js',
        assetFileNames: (info) => {
          const name = (info.names && info.names[0]) || info.name || '';
          return /\.woff2?$/.test(name) ? 'fonts/[name][extname]' : '[name][extname]';
        },
      },
    },
  },
});
