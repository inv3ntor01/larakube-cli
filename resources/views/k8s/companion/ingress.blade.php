apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $driver->getPodName($config) }}-companion
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: {{ $driver->getPodName($config) }}.{{ $config->getName() }}.{{ $config->getLocalTld() }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $driver->getPodName($config) }}-companion
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $driver->getPodName($config) }}.{{ $config->getName() }}.{{ $config->getLocalTld() }}
