apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: typesense
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: typesense.{{ $config->getName() }}.{{ $config->getLocalTld() }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: typesense
                port:
                  number: 8108
  tls:
    - hosts:
        - typesense.{{ $config->getName() }}.{{ $config->getLocalTld() }}
