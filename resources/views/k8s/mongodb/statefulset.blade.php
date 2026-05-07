apiVersion: apps/v1
kind: StatefulSet
metadata:
  name: {{ $driver->value }}
spec:
  serviceName: {{ $driver->value }}
  replicas: 1
  selector:
    matchLabels:
      app: {{ $driver->value }}
  template:
    metadata:
      labels:
        app: {{ $driver->value }}
    spec:
      containers:
        - name: {{ $driver->value }}
          image: {{ $driver->getDockerImage($config) }}
          ports:

            - name: MONGO_INITDB_ROOT_USERNAME
              value: "{{ $driver->dbUsername() }}"
            - name: MONGO_INITDB_ROOT_PASSWORD
              value: "larakubesecretpassword"
          ports:
            - containerPort: {{ $driver->dbPort() }}
          volumeMounts:
            - name: db-data
              mountPath: /data/db
  volumeClaimTemplates:
    - metadata:
        name: db-data
      spec:
        accessModes: ["ReadWriteOnce"]
        resources:
          requests:
            storage: 5Gi
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $driver->value }}
spec:
  selector:
    app: {{ $driver->value }}
  ports:
    - protocol: TCP
      port: {{ $driver->dbPort() }}
      targetPort: {{ $driver->dbPort() }}
  type: ClusterIP
