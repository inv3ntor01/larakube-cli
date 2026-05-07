apiVersion: apps/v1
kind: Deployment
metadata:
  name: meilisearch
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
          image: {{ $driver->getDockerImage($config) }}
          ports:
            - containerPort: 7700
          env:
            - name: MEILI_MASTER_KEY
              valueFrom:
                secretKeyRef:
                  name: laravel-secrets
                  key: MEILISEARCH_KEY
            - name: MEILI_ENV
              value: "production"
          readinessProbe:
            httpGet:
              path: /health
              port: 7700
            initialDelaySeconds: 5
            periodSeconds: 10
          livenessProbe:
            httpGet:
              path: /health
              port: 7700
            initialDelaySeconds: 15
            periodSeconds: 20
          volumeMounts:
            - name: meilisearch-data
              mountPath: /meili_data
      volumes:
        - name: meilisearch-data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-meilisearch-pvc
---
apiVersion: v1
kind: Service
metadata:
  name: laravel-meilisearch
spec:
  selector:
    app: meilisearch
  ports:
    - protocol: TCP
      port: 7700
      targetPort: 7700
  type: ClusterIP
