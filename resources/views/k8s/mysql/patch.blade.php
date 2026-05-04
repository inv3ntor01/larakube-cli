apiVersion: apps/v1
kind: Deployment
metadata:
  name: mysql
spec:
  template:
    spec:
      volumes:
        - name: mysql-data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-mysql-pvc
