// The default theme's own build (I-394): its one stylesheet, into the
// theme's committed public/build, which core's ThemeVite reads.
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite-plus';

export default defineConfig({
    // Rooted at the theme, so the manifest's entry is assets/css/theme.css.
    root: 'themes/default',
    // Where ThemeVite serves the build from: public/themes/{code}/build.
    base: '/themes/default/build/',
    plugins: [
        laravel({
            input: ['assets/css/theme.css'],
            // Both relative to the root: themes/default/public/build.
            publicDirectory: 'public',
            buildDirectory: 'build',
            // Relative to where npm runs, and never the admin's public/hot.
            hotFile: 'themes/default/public/hot',
        }),
    ],
});
