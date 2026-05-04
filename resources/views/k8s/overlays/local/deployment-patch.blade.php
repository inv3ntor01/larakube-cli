apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-web
spec:
  template:
    spec:
      containers:
        - name: php
          imagePullPolicy: IfNotPresent
          volumeMounts:
            - name: code
              mountPath: /var/www/html
      volumes:
        - name: code
          hostPath:
            path: {{ $config->getPath() }}
            type: Directory
