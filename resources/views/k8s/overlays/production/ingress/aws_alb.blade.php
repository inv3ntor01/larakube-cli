alb.ingress.kubernetes.io/scheme: internet-facing
alb.ingress.kubernetes.io/target-type: ip
alb.ingress.kubernetes.io/listen-ports: '[{"HTTP": 80}, {"HTTPS": 443}]'
alb.ingress.kubernetes.io/ssl-redirect: '443'
alb.ingress.kubernetes.io/healthcheck-path: /up
@if($config->id)
# LaraKube Security: CloudFront Verification
# Ensure you set the CLOUDFRONT_ORIGIN_VERIFY_TOKEN in your CI/CD secrets
alb.ingress.kubernetes.io/conditions.php: >-
  [{"field":"http-header","httpHeaderConfig":{"httpHeaderName":"X-Origin-Verify","values":["${CLOUDFRONT_ORIGIN_VERIFY_TOKEN}"]}}]
@endif
