apiVersion: apps/v1
kind: Deployment
metadata:
  name: web
spec:
  replicas: 2
  template:
    spec:
@if($image = $config->getProductionImage())
      containers:
        - name: php
          image: {{ $image }}
          imagePullPolicy: Always
@endif
      imagePullSecrets:
        - name: ghcr-creds
