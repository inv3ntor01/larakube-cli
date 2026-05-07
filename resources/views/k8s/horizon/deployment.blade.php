apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-horizon
spec:
  replicas: 1
  selector:
    matchLabels:
      app: laravel-horizon
  template:
    metadata:
      labels:
        app: laravel-horizon
    spec:
@if($waitCmd = $config->buildWaitForCommand($feature->getDependencies($config)))
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
          args: {!! $feature->getK8sDeploymentArgs() !!}
          envFrom:
            - configMapRef:
                name: laravel-config
            - secretRef:
                name: laravel-secrets
          volumeMounts:
            - name: storage
              mountPath: /var/www/html/storage/logs
              subPath: logs
          livenessProbe:
            exec:
              command: ["healthcheck-horizon"]
            initialDelaySeconds: 15
            periodSeconds: 30
          readinessProbe:
            exec:
              command: ["healthcheck-horizon"]
            initialDelaySeconds: 5
            periodSeconds: 10
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-storage-pvc
