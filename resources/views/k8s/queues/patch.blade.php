apiVersion: apps/v1
kind: Deployment
metadata:
  name: queues
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
              mountPath: /var/www/html/storage/logs
              subPath: logs
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
            - name: data
              mountPath: /var/lib/larakube
@endif
@if($config->isSystem())
            - name: larakube-config
              mountPath: /var/lib/larakube-config
@endif
      volumes:
        - name: code
          hostPath:
            path: {{ $config->getPath() }}
            type: Directory
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-storage-pvc
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
        - name: data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-data-pvc
@endif
@if($config->isSystem())
        - name: larakube-config
          hostPath:
            path: {{ home_path() }}/.larakube
            type: DirectoryOrCreate
@endif
