apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-horizon
spec:
  template:
    spec:
      containers:
        - name: php
          imagePullPolicy: IfNotPresent
          volumeMounts:
            - name: code
              mountPath: /var/www/html
            - name: storage
              $patch: delete
      volumes:
        - name: code
          hostPath:
            path: {{ $config->getPath() }}
            type: Directory
        - name: storage
          $patch: delete
