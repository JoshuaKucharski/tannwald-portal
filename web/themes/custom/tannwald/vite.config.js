// Vite-Konfiguration des Themes: zwei Eingaben (CSS, JS), feste Dateinamen in dist/ ohne Hash,
// kein Manifest, damit tannwald.libraries.yml stabile Pfade referenzieren kann.
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  plugins: [tailwindcss()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: false,
    cssCodeSplit: true,
    sourcemap: false,
    rollupOptions: {
      input: {
        app: 'src/app.js',
        tailwind: 'src/tailwind.css',
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: '[name].js',
        assetFileNames: '[name][extname]',
      },
    },
  },
});
