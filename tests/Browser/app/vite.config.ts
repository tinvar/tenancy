import stylex from '@stylexjs/unplugin';
import rsc from '@vitejs/plugin-rsc';
import { clientComponentChunks, serializableProps } from '@tinvar/renderer/vite';
import { defineConfig } from 'vite';

// The tenancy browser fixture's build: the package's shipped views
// (packages/tenancy/resources), routed by resources/entries/rsc.tsx, in the
// three environments the RSC model has -- built as the skeleton builds an
// application, Meridian bundled into the server builds for StyleX to compile.
const bundled = ['@meridian/ui'];

export default defineConfig({
  plugins: [stylex.vite(), rsc({ clientChunks: clientComponentChunks() }), serializableProps()],
  environments: {
    rsc: {
      resolve: { noExternal: [...bundled, 'lucide-react'] },
      build: { rollupOptions: { input: { index: './resources/entries/rsc.tsx' } } },
    },
    ssr: {
      resolve: { noExternal: [...bundled, 'lucide-react'] },
      build: { rollupOptions: { input: { index: './resources/entries/ssr.tsx' } } },
    },
    client: {
      build: { rollupOptions: { input: { index: './resources/entries/browser.tsx' } } },
    },
  },
  build: {
    emptyOutDir: false,
    rollupOptions: {
      onwarn(warning, warn) {
        if (warning.code === 'MODULE_LEVEL_DIRECTIVE' && warning.message.includes('"use client"')) return;
        warn(warning);
      },
    },
  },
  publicDir: false,
});
