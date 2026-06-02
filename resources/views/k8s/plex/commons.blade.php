{{-- The shared "Commons" services for Plex. Applied with `-n larakube-shared`.
     The plex-commons ConfigMap embeds the spec (self-describing — see plex:export).
     The admin Secret (plex-admin) and the plex-registry ConfigMap are managed by
     the CLI separately so re-running plex:init never rotates the password nor
     wipes tenant allocations. --}}
apiVersion: v1
kind: ConfigMap
metadata:
  name: plex-commons
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
data:
  commons.json: |
{!! $specJsonIndented !!}
@if(($spec['services']['postgres']['enabled'] ?? false))
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: postgres-data
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $spec['services']['postgres']['storage'] }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: postgres
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: postgres
  template:
    metadata:
      labels:
        app: postgres
    spec:
      containers:
        - name: postgres
          image: {{ $spec['services']['postgres']['image'] }}
          ports:
            - containerPort: {{ $spec['services']['postgres']['port'] }}
          env:
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: plex-admin
                  key: POSTGRES_PASSWORD
            - name: PGDATA
              value: /var/lib/postgresql/data/pgdata
          resources:
            requests:
              memory: "128Mi"
              cpu: "100m"
            limits:
              memory: "512Mi"
              cpu: "500m"
          readinessProbe:
            tcpSocket:
              port: {{ $spec['services']['postgres']['port'] }}
            initialDelaySeconds: 5
            periodSeconds: 10
          livenessProbe:
            tcpSocket:
              port: {{ $spec['services']['postgres']['port'] }}
            initialDelaySeconds: 15
            periodSeconds: 20
          volumeMounts:
            - name: data
              mountPath: /var/lib/postgresql/data
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: postgres-data
---
apiVersion: v1
kind: Service
metadata:
  name: postgres
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  selector:
    app: postgres
  ports:
    - protocol: TCP
      port: {{ $spec['services']['postgres']['port'] }}
      targetPort: {{ $spec['services']['postgres']['port'] }}
  type: ClusterIP
@endif
@if(($spec['services']['redis']['enabled'] ?? false))
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: redis
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  replicas: 1
  selector:
    matchLabels:
      app: redis
  template:
    metadata:
      labels:
        app: redis
    spec:
      containers:
        - name: redis
          image: {{ $spec['services']['redis']['image'] }}
          ports:
            - containerPort: {{ $spec['services']['redis']['port'] }}
          resources:
            requests:
              memory: "32Mi"
              cpu: "50m"
            limits:
              memory: "128Mi"
              cpu: "250m"
          readinessProbe:
            exec:
              command: ["redis-cli", "ping"]
            initialDelaySeconds: 2
            periodSeconds: 5
          livenessProbe:
            exec:
              command: ["redis-cli", "ping"]
            initialDelaySeconds: 5
            periodSeconds: 10
---
apiVersion: v1
kind: Service
metadata:
  name: redis
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  selector:
    app: redis
  ports:
    - protocol: TCP
      port: {{ $spec['services']['redis']['port'] }}
      targetPort: {{ $spec['services']['redis']['port'] }}
  type: ClusterIP
@endif
@if(($spec['services']['meilisearch']['enabled'] ?? false))
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: meilisearch-data
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $spec['services']['meilisearch']['storage'] }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: meilisearch
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: meilisearch
  template:
    metadata:
      labels:
        app: meilisearch
    spec:
      containers:
        - name: meilisearch
          image: {{ $spec['services']['meilisearch']['image'] }}
          ports:
            - containerPort: {{ $spec['services']['meilisearch']['port'] }}
          env:
            - name: MEILI_ENV
              value: production
            - name: MEILI_NO_ANALYTICS
              value: "true"
            - name: MEILI_MASTER_KEY
              valueFrom:
                secretKeyRef:
                  name: plex-admin
                  key: MEILI_MASTER_KEY
          resources:
            requests:
              memory: "256Mi"
              cpu: "100m"
            limits:
              memory: "512Mi"
              cpu: "500m"
          volumeMounts:
            - name: data
              mountPath: /meili_data
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: meilisearch-data
---
apiVersion: v1
kind: Service
metadata:
  name: meilisearch
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  selector:
    app: meilisearch
  ports:
    - protocol: TCP
      port: {{ $spec['services']['meilisearch']['port'] }}
      targetPort: {{ $spec['services']['meilisearch']['port'] }}
  type: ClusterIP
@endif
@if(($spec['services']['seaweedfs']['enabled'] ?? false))
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: seaweedfs-data
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $spec['services']['seaweedfs']['storage'] }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: seaweedfs
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: seaweedfs
  template:
    metadata:
      labels:
        app: seaweedfs
    spec:
      containers:
        - name: seaweedfs
          image: {{ $spec['services']['seaweedfs']['image'] }}
          # All-in-one server with the S3 gateway enabled. S3 is in-cluster only
          # (ClusterIP); tenants are isolated by their own bucket.
          args: ["server", "-dir=/data", "-s3"]
          ports:
            - containerPort: {{ $spec['services']['seaweedfs']['port'] }}
          resources:
            requests:
              memory: "256Mi"
              cpu: "100m"
            limits:
              memory: "512Mi"
              cpu: "500m"
          volumeMounts:
            - name: data
              mountPath: /data
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: seaweedfs-data
---
apiVersion: v1
kind: Service
metadata:
  name: seaweedfs
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  selector:
    app: seaweedfs
  ports:
    - protocol: TCP
      port: {{ $spec['services']['seaweedfs']['port'] }}
      targetPort: {{ $spec['services']['seaweedfs']['port'] }}
  type: ClusterIP
@if(! empty($spec['services']['seaweedfs']['host']))
---
# Public S3 endpoint (so tenants can generate public file URLs via AWS_URL).
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: seaweedfs-s3
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: {{ $spec['services']['seaweedfs']['host'] }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: seaweedfs
                port:
                  number: {{ $spec['services']['seaweedfs']['port'] }}
  tls:
    - hosts:
        - {{ $spec['services']['seaweedfs']['host'] }}
@endif
@endif
