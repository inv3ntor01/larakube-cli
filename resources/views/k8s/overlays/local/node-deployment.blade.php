---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-node
spec:
  replicas: 1
  selector:
    matchLabels:
      app: laravel-node
  template:
    metadata:
      labels:
        app: laravel-node
    spec:
      containers:
        - name: node
          image: node:22-alpine
          workingDir: /usr/src/app
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
          livenessProbe:
            tcpSocket:
              port: 5173
            initialDelaySeconds: 15
            periodSeconds: 10
          volumeMounts:
            - name: code
              mountPath: /usr/src/app
      volumes:
        - name: code
          hostPath:
            path: {{ $config->getPath() }}
            type: Directory
---
apiVersion: v1
kind: Service
metadata:
  name: laravel-node
spec:
  selector:
    app: laravel-node
  ports:
    - protocol: TCP
      port: 5173
      targetPort: 5173
  type: ClusterIP
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: laravel-node
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
                name: laravel-node
                port:
                  number: 5173
  tls:
    - hosts:
        - vite-{{ $config->getName() }}.dev.test
