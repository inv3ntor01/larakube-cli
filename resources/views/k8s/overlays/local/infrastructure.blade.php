{{-- 1. Namespace --}}
@include('k8s.overlays.local.namespace')

---
{{-- 2. Local Volume Mapping --}}
@include('k8s.overlays.local.laravel-volumes')
