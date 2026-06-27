    server: {
        host: '0.0.0.0',
        strictPort: true,
        port: 5173,
        hmr: {
            host: process.env.VITE_HMR_HOST || 'vite.{{ $appName }}.{{ $localTld }}',
            clientPort: parseInt(process.env.VITE_HMR_CLIENT_PORT || '443'),
            protocol: process.env.VITE_HMR_PROTOCOL || 'wss',
        },
        cors: true,
    },
