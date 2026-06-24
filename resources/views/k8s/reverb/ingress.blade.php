apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: reverb
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: reverb.{{ $config->getName() }}.{{ $config->getLocalTld() }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: reverb
                port:
                  number: 8080
  tls:
    - hosts:
        - reverb.{{ $config->getName() }}.{{ $config->getLocalTld() }}
