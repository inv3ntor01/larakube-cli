apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $driver->getPodName($config) }}
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $driver->getPodName($config) }}
  template:
    metadata:
      labels:
        app: {{ $driver->getPodName($config) }}
    spec:
      containers:
        # All-in-one: `weed server -s3` runs master (9333), volume (8080), filer
        # (8888) and the S3 gateway (8333) in ONE process. Do NOT add standalone
        # volume/filer containers — they collide with the built-ins on those ports.
        - name: {{ $driver->getPodName($config) }}
          image: {{ $driver->getDockerImage($config) }}
          args: {!! $driver->getK8sDeploymentArgs() !!}
          ports:
            - containerPort: 9333   # master
            - containerPort: 8080   # volume
            - containerPort: 8888   # filer
            - containerPort: 8333   # s3
          readinessProbe:
            httpGet:
              path: /
              port: 8333
            initialDelaySeconds: 3
            periodSeconds: 5
          volumeMounts:
            - name: seaweedfs-data
              mountPath: /data
      volumes:
        - name: seaweedfs-data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-seaweedfs-pvc
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $driver->getPodName($config) }}
spec:
  selector:
    app: {{ $driver->getPodName($config) }}
  ports:
    - name: master
      protocol: TCP
      port: 9333
      targetPort: 9333
    - name: volume
      protocol: TCP
      port: 8080
      targetPort: 8080
    - name: s3
      protocol: TCP
      port: 8333
      targetPort: 8333
    - name: filer
      protocol: TCP
      port: 8888
      targetPort: 8888
  type: ClusterIP
