# Inertia SSR (Server-Side Rendering) server — PRODUCTION ONLY.
#
# A Node.js process that pre-renders Inertia page components and returns HTML
# to the web pod. Reads the pre-built bootstrap/ssr/ssr.js bundle baked into
# the project image during build (production Dockerfile must run
# `npm run build:ssr` as part of its build steps). Listens on the Inertia
# default port 13714, in-cluster only.
#
# This manifest deploys ONLY to production (writes to overlays/production/).
# Local dev does NOT get a SSR pod by default — for Inertia v2 it would add
# 50-200ms round-trip per page with no SEO benefit; for Inertia v3 the Vite
# dev server handles SSR natively via vite-node, making a separate pod
# redundant. Local opt-in support is planned for v2 (see plans/active/).
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $feature->getPodName($config) }}
spec:
  replicas: {{ $config->getStrategy($environment) === \App\Enums\DeploymentStrategy::MULTI_NODE_HA ? 2 : 1 }}
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $feature->getPodName($config) }}
  template:
    metadata:
      labels:
        app: {{ $feature->getPodName($config) }}
    spec:
      containers:
        - name: {{ $feature->getPodName($config) }}
          image: {{ $config->getName() }}:latest
          imagePullPolicy: Always
          workingDir: /var/www/html
          command: {!! $config->getPackageManager()->getSsrStartCommand() !!}
          ports:
            - containerPort: 13714
@php($resources = $config->getResources($environment ?? 'local', 'ssr'))
@if(!empty($resources['requests']) || !empty($resources['limits']))
          resources:
@if(!empty($resources['requests']))
            requests:
@foreach($resources['requests'] as $dim => $val)
              {{ $dim }}: "{{ $val }}"
@endforeach
@endif
@if(!empty($resources['limits']))
            limits:
@foreach($resources['limits'] as $dim => $val)
              {{ $dim }}: "{{ $val }}"
@endforeach
@endif
@endif
          envFrom:
            - configMapRef:
                name: laravel-config
            - secretRef:
                name: laravel-secrets
          env:
            - name: AUTORUN_ENABLED
              value: "false"
            - name: AUTORUN_LARAVEL_MIGRATION
              value: "false"
          readinessProbe:
            tcpSocket:
              port: 13714
            initialDelaySeconds: 5
            periodSeconds: 5
@if($pullSecret = $config->getImagePullSecret($environment))
      imagePullSecrets:
        - name: {{ $pullSecret }}
@endif
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $feature->getPodName($config) }}
spec:
  selector:
    app: {{ $feature->getPodName($config) }}
  ports:
    - protocol: TCP
      port: 13714
      targetPort: 13714
  type: ClusterIP
