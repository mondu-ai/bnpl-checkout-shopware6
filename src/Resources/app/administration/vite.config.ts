import { defineConfig } from 'vite';
import symfonyPlugin from 'vite-plugin-symfony';
import path from 'path';

// TwigPlugin: converts .html.twig files to string exports (same as SW6 admin build)
function twigPlugin() {
    return {
        name: 'shopware-vite-plugin-twigjs',
        transform(code: string, id: string) {
            if (!id.endsWith('.twig')) return;
            const normalized = code
                .replace(/<!--[\s\S]*?-->/gm, '')
                .trim()
                .replace(/\s+/g, ' ')
                .replace(/\\/g, '\\\\')
                .replace(/"/g, '\\"')
                .replace(/\$/g, '\\$')
                .replace(/\n/g, ' ')
                .replace(/\r/g, ' ');
            return { code: `export default "${normalized}"` };
        }
    };
}

// ExternalsPlugin: Vue is accessed via global Shopware.Vue (same as SW6 admin build)
function externalsPlugin() {
    return {
        name: 'shopware-vite-plugin-vue-globals',
        resolveId(id: string) {
            if (id === 'vue') return '\0vue-external';
        },
        load(id: string) {
            if (id === '\0vue-external') {
                return `export default window?.Shopware?.Vue; export const h = window?.Shopware?.Vue?.h;`;
            }
        }
    };
}

export default defineConfig({
    plugins: [
        twigPlugin(),
        externalsPlugin(),
        symfonyPlugin(),
    ],
    resolve: {
        alias: {
            'Mond1SW6': path.resolve(__dirname),
        }
    },
    // Base URL matches the bundle path in Shopware
    base: '/bundles/mond1sw6/administration/',
    build: {
        // Output to Resources/public/administration/ — picked up by assets:install
        outDir: path.resolve(__dirname, '../../public/administration'),
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: {
                'mond1-s-w6': path.resolve(__dirname, 'src/main.ts'),
            },
            output: {
                entryFileNames: 'assets/[name]-[hash].js',
            },
        },
    },
});