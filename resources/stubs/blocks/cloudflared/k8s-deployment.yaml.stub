apiVersion: apps/v1
kind: Deployment
metadata:
  name: larakube-share
spec:
  replicas: 1
  selector:
    matchLabels:
      app: larakube-share
  template:
    metadata:
      labels:
        app: larakube-share
    spec:
      containers:
      - name: cloudflared
        image: cloudflare/cloudflared:latest
        args:
        - tunnel
        - --url
        - http://laravel-web:80
        - --no-autoupdate
