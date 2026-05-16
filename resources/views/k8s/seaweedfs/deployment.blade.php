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
        - name: {{ $driver->getPodName($config) }}
          image: {{ $driver->getDockerImage($config) }}
          args: {!! $driver->getK8sDeploymentArgs() !!}

          ports:
            - containerPort: 9333
        - name: volume
          image: {{ $driver->getDockerImage($config) }}
          args: ["volume", "-mserver=localhost:9333", "-port=8081"]
          ports:
            - containerPort: 8081
        - name: filer
          image: {{ $driver->getDockerImage($config) }}
          args: ["filer", "-master=localhost:9333", "-s3"]
          env:
            - name: AWS_ACCESS_KEY_ID
              value: "larakube"
            - name: AWS_SECRET_ACCESS_KEY
              value: "larakubesecretpassword"
          ports:
            - containerPort: 8888
            - containerPort: 8333
          readinessProbe:
            httpGet:
              path: /
              port: 8333
            initialDelaySeconds: 2
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
      port: 8081
      targetPort: 8081
    - name: s3
      protocol: TCP
      port: 8333
      targetPort: 8333
    - name: filer
      protocol: TCP
      port: 8888
      targetPort: 8888
  type: ClusterIP
