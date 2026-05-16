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
                  $patch: delete
          volumes:
            - name: code
              hostPath:
                path: {{ $config->getPath() }}
                type: Directory
            - name: storage
              $patch: delete
