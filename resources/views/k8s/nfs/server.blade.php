{{-- NFS server: a single pod re-exporting a block-backed (RWO) PVC over NFS, so
     the larakube-nfs StorageClass can hand out ReadWriteMany volumes on managed
     clusters whose block storage is RWO-only. Applied FIRST and waited on (its
     readiness probe means "actually serving on :2049"), because the provisioner
     mounts this export at pod-create time — if it isn't up, the provisioner pod
     wedges in ContainerCreating.

     Caveat: this pod is pinned to its RWO backing volume's node — a soft SPOF for
     STORAGE (app pods stay HA). --}}
apiVersion: v1
kind: Namespace
metadata:
  name: nfs
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: nfs-server-backing
  namespace: nfs
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $size }}
@isset($storageClass)
  storageClassName: {{ $storageClass }}
@endisset
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: nfs-server
  namespace: nfs
  labels:
    app: nfs-server
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: nfs-server
  template:
    metadata:
      labels:
        app: nfs-server
    spec:
      containers:
        - name: nfs-server
          image: itsthenetwork/nfs-server-alpine:12
          securityContext:
            privileged: true
          env:
            - name: SHARED_DIRECTORY
              value: /exports
          ports:
            - name: nfs
              containerPort: 2049
          readinessProbe:
            tcpSocket:
              port: 2049
            initialDelaySeconds: 5
            periodSeconds: 5
            failureThreshold: 6
          livenessProbe:
            tcpSocket:
              port: 2049
            initialDelaySeconds: 15
            periodSeconds: 20
          resources:
            requests:
              cpu: 50m
              memory: 64Mi
          volumeMounts:
            - name: backing
              mountPath: /exports
      volumes:
        - name: backing
          persistentVolumeClaim:
            claimName: nfs-server-backing
---
{{-- Headless (clusterIP: None) on purpose: the DNS name then resolves straight to
     the server POD IP, so the kubelet (host netns) mounts the pod directly instead
     of going host → ClusterIP, which Cilium/kube-proxy can black-hole (mount hangs
     with no error, even though pod → ClusterIP:2049 works). --}}
apiVersion: v1
kind: Service
metadata:
  name: nfs-server
  namespace: nfs
spec:
  clusterIP: None
  selector:
    app: nfs-server
  ports:
    - name: nfs
      port: 2049
      targetPort: 2049
