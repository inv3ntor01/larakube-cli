apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $driver->value }}-companion
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $driver->value }}-companion
  template:
    metadata:
      labels:
        app: {{ $driver->value }}-companion
    spec:
      containers:
        - name: companion
          image: {{ $driver->getCompanionDockerImage() }}
          ports:
            - containerPort: {{ $driver->getCompanionPort() }}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $driver->getPodName($config) }}-companion
spec:
  selector:
    app: {{ $driver->getPodName($config) }}-companion
  ports:
    - protocol: TCP
      port: 80
      targetPort: {{ $driver->getCompanionPort() }}
  type: ClusterIP
