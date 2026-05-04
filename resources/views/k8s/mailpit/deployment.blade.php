---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: mailpit
spec:
  replicas: 1
  selector:
    matchLabels:
      app: mailpit
  template:
    metadata:
      labels:
        app: mailpit
    spec:
      containers:
        - name: mailpit
          image: axllent/mailpit
          ports:
            - containerPort: 8025
            - containerPort: 1025
          readinessProbe:
            httpGet:
              path: /
              port: 8025
            initialDelaySeconds: 2
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /
              port: 8025
            initialDelaySeconds: 5
            periodSeconds: 10
---
apiVersion: v1
kind: Service
metadata:
  name: mailpit
spec:
  selector:
    app: mailpit
  ports:
    - name: dashboard
      protocol: TCP
      port: 8025
      targetPort: 8025
    - name: smtp
      protocol: TCP
      port: 1025
      targetPort: 1025
  type: ClusterIP
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: mailpit
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: mailpit.{{ $config->getName() }}.dev.test
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: mailpit
                port:
                  number: 8025
  tls:
    - hosts:
        - mailpit.{{ $config->getName() }}.dev.test
