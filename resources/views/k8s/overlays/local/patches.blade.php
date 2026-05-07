{{-- 1. Deployment Patches (Local) --}}
@include('k8s.overlays.local.deployment-patch')

---
{{-- 2. Ingress Patches (Local) --}}
@include('k8s.overlays.local.ingress-patch')
