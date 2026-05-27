# The hot-file URL is sourced from vite.config.js#server.origin (the only lever
# laravel-vite-plugin honors). Do not add VITE_DEV_SERVER_URL here — the plugin
# does not read it. Liveness probes are intentionally omitted: a SIGTERM to the
# dev server triggers the plugin's cleanup handler and deletes public/hot.
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: node
spec:
  replicas: 1
  selector:
    matchLabels:
      app: node
  template:
    metadata:
      labels:
        app: node
    spec:
      containers:
        - name: node
          image: {{ $config->getName() }}:latest
          workingDir: /var/www/html
          command: {!! $config->getPackageManager()->getReadinessProbeCommand() !!}
          ports:
            - containerPort: 5173
          envFrom:
            - configMapRef:
                name: laravel-config
          readinessProbe:
            tcpSocket:
              port: 5173
            initialDelaySeconds: 5
            periodSeconds: 5
          volumeMounts:
            - name: code
              mountPath: /var/www/html
      volumes:
        - name: code
          hostPath:
            path: {{ $config->getPath() }}
            type: Directory
---
apiVersion: v1
kind: Service
metadata:
  name: node
spec:
  selector:
    app: node
  ports:
    - protocol: TCP
      port: 5173
      targetPort: 5173
  type: ClusterIP
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: node
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: vite-{{ $config->getName() }}.dev.test
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: node
                port:
                  number: 5173
  tls:
    - hosts:
        - vite-{{ $config->getName() }}.dev.test
