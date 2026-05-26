@foreach(['web', 'horizon', 'queues', 'reverb', 'node'] as $name)
@php($feature = \App\Enums\LaravelFeature::fromPodName($name))
@if($name === 'web' || ($feature && $config->hasFeature($feature)) || ($name === 'node' && $config->getFrontend()?->requiresNodePod()))
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $name }}
spec:
  template:
    spec:
      containers:
        - name: {{ $name === 'node' ? 'node' : 'php' }}
          imagePullPolicy: IfNotPresent
          volumeMounts:
            - name: code
              mountPath: /var/www/html
@if($name !== 'node')
            - name: storage
              mountPath: /var/www/html/storage/logs
              subPath: logs
            - name: storage
              mountPath: /var/www/html/bootstrap/cache
              subPath: bootstrap/cache
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
@endif
      volumes:
        - name: code
          hostPath:
            path: "{{ $config->getPath() }}"
            type: Directory
@if($name !== 'node')
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-storage-pvc
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
        - name: data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-data-pvc
@endif
@endif
@endif
@endforeach
@if($config->hasFeature(\App\Enums\LaravelFeature::TASK_SCHEDULING))
---
apiVersion: batch/v1
kind: CronJob
metadata:
  name: scheduler
spec:
  jobTemplate:
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
                - name: storage
                  mountPath: /var/www/html/bootstrap/cache
                  subPath: bootstrap/cache
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
            - name: code
              hostPath:
                path: "{{ $config->getPath() }}"
                type: Directory
            - name: storage
              persistentVolumeClaim:
                claimName: {{ $config->getName() }}-laravel-storage-pvc
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
            - name: data
              persistentVolumeClaim:
                claimName: {{ $config->getName() }}-laravel-data-pvc
@endif
@endif
