apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: meilisearch
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: meilisearch.{{ $config->getName() }}.dev.test
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: meilisearch
                port:
                  number: 7700
  tls:
    - hosts:
        - meilisearch.{{ $config->getName() }}.dev.test
