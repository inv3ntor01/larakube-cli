    server: {
        host: '0.0.0.0',
        strictPort: true,
        port: 5173,
        hmr: {
            host: 'vite-{{ $appName }}.dev.test',
            clientPort: 443,
            protocol: 'wss',
        },
        cors: true,
    },
