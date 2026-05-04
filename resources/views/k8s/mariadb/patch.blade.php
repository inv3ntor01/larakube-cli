apiVersion: apps/v1
kind: Deployment
metadata:
  name: mariadb
spec:
  template:
    spec:
      volumes:
        - name: db-data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-mariadb-pvc
