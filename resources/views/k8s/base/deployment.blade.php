apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-web
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: laravel-web
  template:
    metadata:
      labels:
        app: laravel-web
    spec:
@if($waitCmd = $config->buildWaitForCommand($config->getCoreDependencies()))
      initContainers:
        - name: wait-for-deps
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command: {!! $waitCmd !!}
@endif
      containers:
        - name: php
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          args: {!! $command !!}
          ports:
            - containerPort: {{ $config->getServerVariation()->containerPort() }}
          env:
            - name: AUTORUN_ENABLED
              value: "true"
            - name: AUTORUN_LARAVEL_MIGRATION
              value: "true"
          envFrom:
            - configMapRef:
                name: laravel-config
            - secretRef:
                name: laravel-secrets
          livenessProbe:
            httpGet:
              path: /up
              port: {{ $config->getServerVariation()->containerPort() }}
            initialDelaySeconds: 30
            periodSeconds: 30
          readinessProbe:
            httpGet:
              path: /up
              port: {{ $config->getServerVariation()->containerPort() }}
            initialDelaySeconds: 10
            periodSeconds: 10

          volumeMounts:
            - name: storage
              mountPath: /var/www/html/storage/logs
              subPath: logs
            - name: storage
              mountPath: /var/www/html/storage/framework/sessions
              subPath: framework/sessions
            - name: storage
              mountPath: /var/www/html/storage/framework/views
              subPath: framework/views
            - name: storage
              mountPath: /var/www/html/storage/framework/cache
              subPath: framework/cache
            - name: storage
              mountPath: /var/www/html/storage/app/public
              subPath: app/public
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
            - name: data
              mountPath: /var/lib/larakube
@endif
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-storage-pvc
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
        - name: data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-data-pvc
@endif
