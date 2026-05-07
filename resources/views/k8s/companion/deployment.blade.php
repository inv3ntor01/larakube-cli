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
@if($driver instanceof \App\Enums\DatabaseDriver)
          env:
@if($driver === \App\Enums\DatabaseDriver::MYSQL || $driver === \App\Enums\DatabaseDriver::MARIADB)
            - name: PMA_HOST
              value: {{ $driver->value }}
            - name: PMA_USER
              valueFrom:
                configMapKeyRef:
                  name: laravel-config
                  key: DB_USERNAME
            - name: PMA_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: laravel-secrets
                  key: DB_PASSWORD
@elseif($driver === \App\Enums\DatabaseDriver::POSTGRESQL)
            - name: ADMINER_DEFAULT_SERVER
              value: {{ $driver->value }}
@elseif($driver === \App\Enums\DatabaseDriver::MONGODB)
            - name: ME_CONFIG_MONGODB_SERVER
              value: {{ $driver->value }}
            - name: ME_CONFIG_MONGODB_ADMINUSERNAME
              valueFrom:
                configMapKeyRef:
                  name: laravel-config
                  key: DB_USERNAME
            - name: ME_CONFIG_MONGODB_ADMINPASSWORD
              valueFrom:
                secretKeyRef:
                  name: laravel-secrets
                  key: DB_PASSWORD
@endif
@elseif($driver instanceof \App\Enums\CacheDriver)
@if($driver === \App\Enums\CacheDriver::REDIS)
          env:
            - name: REDIS_HOSTS
              value: local:redis:6379
@endif
@endif
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $driver->value }}-companion
spec:
  selector:
    app: {{ $driver->value }}-companion
  ports:
    - protocol: TCP
      port: 80
      targetPort: {{ $driver->getCompanionPort() }}
  type: ClusterIP
