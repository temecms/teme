import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
                // One stylesheet per card, which the public page links only when it draws that card (I-248).
                'resources/css/cards/image.css',
                'resources/css/cards/gallery.css',
                'resources/css/cards/embed.css',
                'resources/css/cards/callout.css',
                'resources/css/cards/snippet.css',
                'resources/css/cards/button.css',
                'resources/css/cards/audio.css',
                'resources/css/cards/video.css',
                'resources/css/cards/imported.css',
            ],
            refresh: true,
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
                '**/Reference/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'build/**',
            'Reference/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            '.ai/**',
            'docs/**',
            'Reference/**',
            '*.md',
            'composer.json',
            'boost.json',
            '.mcp.json',
            'opencode.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
            // Golden files, compared byte for byte by the renderer tests.
            'tests/Fixtures/**',
            // A theme's committed build: minified CSS and Vite's own manifest.
            'themes/*/public/build/**',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            entryPoint: 'resources/css/app.css',
        },
    },
});
