    optimizeDeps: {
        // Pre-bundle dependencies from a full source crawl at startup so Vite
        // doesn't discover them lazily mid-session and trigger a hard page reload
        // (which wipes form input). Covers icon libraries and other deps imported
        // deep inside components that the default entry scan can miss.
        entries: ['resources/js/**/*.{js,ts,jsx,tsx,vue,svelte}'],
    },
