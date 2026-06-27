apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: garage
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: s3.{{ $config->getName() }}.{{ $config->getLocalTld() }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: garage
                port:
                  number: 3900
    - host: s3-web.{{ $config->getName() }}.{{ $config->getLocalTld() }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: garage
                port:
                  number: 3902
  tls:
    - hosts:
        - s3.{{ $config->getName() }}.{{ $config->getLocalTld() }}
        - s3-web.{{ $config->getName() }}.{{ $config->getLocalTld() }}
