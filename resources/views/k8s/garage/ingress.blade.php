apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: garage
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
spec:
  rules:
    - host: s3-{{ $config->getName() }}.dev.test
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: garage
                port:
                  number: 3900
    - host: s3-web-{{ $config->getName() }}.dev.test
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: garage
                port:
                  number: 3902
