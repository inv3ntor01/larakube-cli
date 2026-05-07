{{-- 1. Deployment --}}
@include('k8s.base.deployment')

---
{{-- 2. Service --}}
@include('k8s.base.service')

---
{{-- 3. Ingress --}}
@include('k8s.base.ingress')
