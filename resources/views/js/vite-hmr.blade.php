    server: {
        host: '0.0.0.0',
        strictPort: true,
        port: 5173,
        hmr: {
            host: 'vite.{{ $appName }}.kube',
            clientPort: 443,
            protocol: 'wss',
        },
        cors: true,
    },
