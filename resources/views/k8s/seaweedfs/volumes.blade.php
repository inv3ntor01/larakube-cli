apiVersion: v1
kind: PersistentVolume
metadata:
  name: {{ $config->getName() }}-seaweedfs-pv
  labels:
    larakube-project: {{ $config->getName() }}
spec:
  capacity:
    storage: 5Gi
  accessModes:
    - ReadWriteOnce
  persistentVolumeReclaimPolicy: Retain
  storageClassName: ""
  hostPath:
    path: {{ $config->getPath() }}/.infrastructure/volume_data/seaweedfs
    type: DirectoryOrCreate
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $config->getName() }}-seaweedfs-pvc
spec:
  accessModes:
    - ReadWriteOnce
  storageClassName: ""
  resources:
    requests:
      storage: 5Gi
  volumeName: {{ $config->getName() }}-seaweedfs-pv
