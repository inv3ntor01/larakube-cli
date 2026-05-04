apiVersion: apps/v1
kind: Deployment
metadata:
  name: typesense
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: typesense
  template:
    metadata:
      labels:
        app: typesense
    spec:
      containers:
        - name: typesense
          image: {{ $driver->getDockerImage() }}
          ports:
            - containerPort: 8108
          env:
            - name: TYPESENSE_API_KEY
              valueFrom:
                secretKeyRef:
                  name: laravel-secrets
                  key: TYPESENSE_API_KEY
            - name: TYPESENSE_DATA_DIR
              value: "/typesense_data"
          readinessProbe:
            httpGet:
              path: /health
              port: 8108
            initialDelaySeconds: 5
            periodSeconds: 10
          livenessProbe:
            httpGet:
              path: /health
              port: 8108
            initialDelaySeconds: 15
            periodSeconds: 20
          volumeMounts:
            - name: typesense-data
              mountPath: /typesense_data
      volumes:
        - name: typesense-data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-typesense-pvc
---
apiVersion: v1
kind: Service
metadata:
  name: laravel-typesense
spec:
  selector:
    app: typesense
  ports:
    - protocol: TCP
      port: 8108
      targetPort: 8108
  type: ClusterIP
