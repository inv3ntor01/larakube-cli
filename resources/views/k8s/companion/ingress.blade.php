apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $driver->value }}-companion
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: {{ $driver->value }}-{{ $config->getName() }}.dev.test
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $driver->value }}-companion
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $driver->value }}-{{ $config->getName() }}.dev.test
