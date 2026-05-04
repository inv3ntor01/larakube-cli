apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-queue
spec:
  replicas: 1
  selector:
    matchLabels:
      app: laravel-queue
  template:
    metadata:
      labels:
        app: laravel-queue
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
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-storage-pvc
