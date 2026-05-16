{{-- 1. Persistence --}}
@include('k8s.base.pvc')

---
{{-- 2. Configuration --}}
@include('k8s.base.configmap')
