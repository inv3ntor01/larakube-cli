apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $driver->getPodName($config) }}-companion
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
spec:
  rules:
    - host: {{ $driver->getPodName($config) }}-{{ $config->getName() }}.dev.test
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $driver->getPodName($config) }}-companion
                port:
                  number: 80
