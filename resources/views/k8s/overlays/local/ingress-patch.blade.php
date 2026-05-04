apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: laravel-web
  annotations:
    cert-manager.io/cluster-issuer: null
spec:
  tls:
    - hosts:
        - {{ $config->getName() }}.dev.test
