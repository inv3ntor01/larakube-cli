apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-reverb
spec:
  replicas: 1
  selector:
    matchLabels:
      app: laravel-reverb
  template:
    metadata:
      labels:
        app: laravel-reverb
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
          ports:
            - containerPort: 8080
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
              command: ["healthcheck-reverb"]
            initialDelaySeconds: 15
            periodSeconds: 30
          readinessProbe:
            exec:
              command: ["healthcheck-reverb"]
            initialDelaySeconds: 5
            periodSeconds: 10
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-storage-pvc
---
apiVersion: v1
kind: Service
metadata:
  name: laravel-reverb
spec:
  selector:
    app: laravel-reverb
  ports:
    - protocol: TCP
      port: 8080
      targetPort: 8080
  type: ClusterIP
