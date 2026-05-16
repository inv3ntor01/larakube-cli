{{-- 1. Deployment Patches (Local) --}}
@include('k8s.overlays.local.deployment-patch')

---
{{-- 2. PVC Patches (Local) --}}
@include('k8s.overlays.local.pvc-patch')

---
{{-- 3. Ingress Patches (Local) --}}
@include('k8s.overlays.local.ingress-patch')
