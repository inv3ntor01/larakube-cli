apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $config->getServerVariation()->getPodName($config) }}
  annotations:
@if($view = $config->getIngress($environment)?->getAnnotationView())
{{-- We use a custom indent filter to ensure every line of the included view is aligned --}}
{!! preg_replace('/^/m', '    ', trim(view($view, ['config' => $config])->render())) !!}
@else
    traefik.ingress.kubernetes.io/router.tls: "true"
@if($config->getStrategy() === \App\Enums\DeploymentStrategy::SINGLE_NODE)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@endif
@endif
spec:
@if($config->getIngress($environment)?->getIngressClass())
  ingressClassName: {{ $config->getIngress($environment)->getIngressClass() }}
@endif
  rules:
    - host: {{ $config->getWebHost($environment) }}
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
        - {{ $config->getWebHost($environment) }}
@if($config->getStrategy() === \App\Enums\DeploymentStrategy::SINGLE_NODE)
      secretName: {{ $config->getName() }}-tls
@endif
