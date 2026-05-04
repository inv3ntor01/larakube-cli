apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: laravel-web
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
    traefik.ingress.kubernetes.io/service.serversscheme: {{ $config->getServerVariation()->traefikScheme() }}
spec:
  rules:
    - host: {{ $config->getName() }}.dev.test
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: laravel-web
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $config->getName() }}.dev.test
