apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: garage
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
                name: laravel-garage
                port:
                  number: 3900
  tls:
    - hosts:
        - s3.{{ $config->getName() }}.dev.test
