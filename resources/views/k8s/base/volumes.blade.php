{{-- App storage PVCs. Only rendered for SINGLE-NODE envs — multi-node app pods
     use a per-pod emptyDir instead (the engine skips app-volumes for multi-node),
     since block storage can't share a ReadWriteOnce volume across nodes. Hence
     always ReadWriteOnce here. --}}
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $config->getName() }}-laravel-storage-pvc
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 1Gi
@if($config->environments[$environment]->storageClass)
  storageClassName: {{ $config->environments[$environment]->storageClass }}
@endif
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $config->getName() }}-laravel-data-pvc
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 1Gi
@if($config->environments[$environment]->storageClass)
  storageClassName: {{ $config->environments[$environment]->storageClass }}
@endif
