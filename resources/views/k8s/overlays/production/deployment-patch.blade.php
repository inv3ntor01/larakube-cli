apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-web
spec:
  replicas: 2
  template:
    spec:
      imagePullSecrets:
        - name: ghcr-creds
