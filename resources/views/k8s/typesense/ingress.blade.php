apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: typesense
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
spec:
  rules:
    - host: typesense-{{ $config->getName() }}.dev.test
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: typesense
                port:
                  number: 8108
