apiVersion: apps/v1
kind: Deployment
metadata:
  name: horizon
spec:
  template:
    spec:
      containers:
        - name: php
          imagePullPolicy: IfNotPresent
          volumeMounts:
            - name: code
              mountPath: /var/www/html
@if($config->isSystem())
            - name: larakube-config
              mountPath: /var/lib/larakube
@endif
            - name: storage
              $patch: delete
      volumes:
        - name: code
          hostPath:
            path: {{ $config->getPath() }}
            type: Directory
@if($config->isSystem())
        - name: larakube-config
          hostPath:
            path: {{ home_path() }}/.larakube
            type: DirectoryOrCreate
@endif
        - name: storage
          $patch: delete
