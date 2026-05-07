apiVersion: apps/v1
kind: Deployment
metadata:
  name: memcached
spec:
  replicas: 1
  selector:
    matchLabels:
      app: memcached
  template:
    metadata:
      labels:
        app: memcached
    spec:
      containers:
        - name: memcached
          image: {{ $driver->getDockerImage($config) }}
          ports:
            - containerPort: 11211
          readinessProbe:
            tcpSocket:
              port: 11211
            initialDelaySeconds: 2
            periodSeconds: 5
          livenessProbe:
            tcpSocket:
              port: 11211
            initialDelaySeconds: 5
            periodSeconds: 10
---
apiVersion: v1
kind: Service
metadata:
  name: memcached-server
spec:
  selector:
    app: memcached
  ports:
    - protocol: TCP
      port: 11211
      targetPort: 11211
  type: ClusterIP
