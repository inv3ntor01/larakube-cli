@php($shared = $config->getStrategy($environment) === \App\Enums\DeploymentStrategy::MULTI_NODE_HA && $config->usesSharedStorage($environment))
{{-- App storage PVC. Single-node → ReadWriteOnce (block storage). Multi-node with
     sharedStorage → ReadWriteMany on the in-cluster NFS class (cloud:provision:nfs).
     Multi-node WITHOUT sharedStorage doesn't render this at all — the engine skips
     app-volumes and the app pods use a per-pod emptyDir instead. --}}
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $config->getName() }}-laravel-storage-pvc
spec:
  accessModes:
    - {{ $shared ? 'ReadWriteMany' : 'ReadWriteOnce' }}
  resources:
    requests:
      storage: 1Gi
@if($shared)
  storageClassName: {{ \App\Data\ConfigData::NFS_STORAGE_CLASS }}
@elseif($config->environments[$environment]->storageClass)
  storageClassName: {{ $config->environments[$environment]->storageClass }}
@endif
---
{{-- SQLite data PVC. SQLite is single-node by nature (its DB is a file), so this
     is always ReadWriteOnce. --}}
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
@if(! $shared && $config->environments[$environment]->storageClass)
  storageClassName: {{ $config->environments[$environment]->storageClass }}
@endif
