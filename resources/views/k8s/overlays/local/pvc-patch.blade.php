apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $config->getName() }}-laravel-storage-pvc
spec:
  accessModes:
    - ReadWriteOnce
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $config->getName() }}-laravel-data-pvc
spec:
  accessModes:
    - ReadWriteOnce
@endif
