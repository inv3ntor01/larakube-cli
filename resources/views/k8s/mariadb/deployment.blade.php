apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $driver->value }}
spec:
  replicas: 1
  strategy:
    type: Recreate
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
          image: {{ $driver->getDockerImage() }}
          ports:
            - containerPort: {{ $driver->dbPort() }}
          env:
            - name: MARIADB_DATABASE
              valueFrom:
                configMapKeyRef:
                  name: laravel-config
                  key: DB_DATABASE
            - name: MARIADB_USER
              valueFrom:
                configMapKeyRef:
                  name: laravel-config
                  key: DB_USERNAME
            - name: MARIADB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: laravel-secrets
                  key: DB_PASSWORD
            - name: MARIADB_ROOT_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: laravel-secrets
                  key: DB_PASSWORD
          readinessProbe:
            tcpSocket:
              port: {{ $driver->dbPort() }}
            initialDelaySeconds: 5
            periodSeconds: 10
          livenessProbe:
            tcpSocket:
              port: {{ $driver->dbPort() }}
            initialDelaySeconds: 15
            periodSeconds: 20
          volumeMounts:
            - name: db-data
              mountPath: /var/lib/mysql
      volumes:
        - name: db-data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-mariadb-pvc
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
