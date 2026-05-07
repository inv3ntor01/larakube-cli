{{-- 1. Persistence --}}
@include('k8s.base.pvc')

---
{{-- 2. Configuration --}}
@include('k8s.base.configmap')

---
{{-- 3. Secrets --}}
@include('k8s.base.secrets')
