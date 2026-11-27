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
                // The admin's fonts alone, which the error pages link (issue 533).
                'resources/css/admin-fonts.css',
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
                'resources/css/cards/featured-posts.css',
                'resources/css/cards/faq.css',
                'resources/css/cards/steps.css',
                'resources/css/cards/table.css',
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
    build: {
        rolldownOptions: {
            output: {
                codeSplitting: {
                    groups: [
                        // Every icon a page imports by name in one file, instead of one small file an icon (I-3311).
                        {
                            name: 'icons',
                            test: /lucide-react[\\/]dist[\\/]esm[\\/]icons[\\/](?!index\.js)[^\\/]+\.js$/,
                            tags: ['$initial'],
                        },
                        // The design system's components in one file, instead of a small file for each one two
                        // screens share (I-3443). Each screen's own code still loads only when it opens.
                        {
                            name: 'ui',
                            test: /resources[\\/]js[\\/]components[\\/]ui[\\/]/,
                        },
                    ],
                },
            },
        },
    },
    server: {
        // A CSP source can't be an IPv6 literal, so the dev server announces a name.
        host: 'localhost',
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
