apiVersion: apps/v1
kind: Deployment
metadata:
  name: minio
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: minio
  template:
    metadata:
      labels:
        app: minio
    spec:
      containers:
        - name: minio
          image: {{ $driver->getDockerImage($config) }}
          args: {!! $driver->getK8sDeploymentArgs() !!}
          ports:
            - containerPort: 9000
            - containerPort: 9001
          env:
            - name: MINIO_ROOT_USER
              value: "larakube"
            - name: MINIO_ROOT_PASSWORD
              value: "larakubesecretpassword"
          readinessProbe:
            httpGet:
              path: /minio/health/ready
              port: 9000
            initialDelaySeconds: 5
            periodSeconds: 10
          livenessProbe:
            httpGet:
              path: /minio/health/live
              port: 9000
            initialDelaySeconds: 15
            periodSeconds: 20
          volumeMounts:
            - name: minio-data
              mountPath: /data
      volumes:
        - name: minio-data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-minio-pvc
---
apiVersion: v1
kind: Service
metadata:
  name: laravel-minio
spec:
  selector:
    app: minio
  ports:
    - name: api
      protocol: TCP
      port: 9000
      targetPort: 9000
    - name: console
      protocol: TCP
      port: 9001
      targetPort: 9001
  type: ClusterIP
