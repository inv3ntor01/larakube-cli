apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: minio
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: s3.{{ $config->getName() }}.dev.test
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: laravel-minio
                port:
                  number: 9000
    - host: s3-console.{{ $config->getName() }}.dev.test
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: laravel-minio
                port:
                  number: 9001
  tls:
    - hosts:
        - s3.{{ $config->getName() }}.dev.test
        - s3-console.{{ $config->getName() }}.dev.test
