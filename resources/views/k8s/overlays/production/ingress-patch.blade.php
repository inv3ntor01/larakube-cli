apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $config->getServerVariation()->getPodName($config) }}
  annotations:
    traefik.ingress.kubernetes.io/router.tls: "true"
@if($config->getStrategy() === \App\Enums\DeploymentStrategy::SINGLE_NODE)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@endif
spec:
  rules:
    - host: {{ $config->getProductionHost() }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $config->getServerVariation()->getPodName($config) }}
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $config->getProductionHost() }}
@if($config->getStrategy() === \App\Enums\DeploymentStrategy::SINGLE_NODE)
      secretName: {{ $config->getName() }}-tls
@endif
