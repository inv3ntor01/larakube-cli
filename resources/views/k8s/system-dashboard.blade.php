apiVersion: v1
kind: Namespace
metadata:
  name: larakube-system
  labels:
    larakube.io/managed-by: larakube
---
apiVersion: v1
kind: ServiceAccount
metadata:
  name: larakube-dashboard
  namespace: larakube-system
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: larakube-dashboard-role
rules:
  - apiGroups: [""]
    resources: ["namespaces", "pods", "services"]
    verbs: ["get", "list", "watch"]
  - apiGroups: ["apps"]
    resources: ["deployments", "statefulsets", "replicasets"]
    verbs: ["get", "list", "watch"]
  - apiGroups: ["networking.k8s.io"]
    resources: ["ingresses"]
    verbs: ["get", "list", "watch"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: larakube-dashboard-binding
subjects:
  - kind: ServiceAccount
    name: larakube-dashboard
    namespace: larakube-system
roleRef:
  kind: ClusterRole
  name: larakube-dashboard-role
  apiClass: rbac.authorization.k8s.io
---
apiVersion: v1
kind: Service
metadata:
  name: larakube-dashboard
  namespace: larakube-system
spec:
  selector:
    app: larakube-dashboard
  ports:
    - protocol: TCP
      port: 80
      targetPort: 8080
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: larakube-dashboard
  namespace: larakube-system
spec:
  replicas: 1
  selector:
    matchLabels:
      app: larakube-dashboard
  template:
    metadata:
      labels:
        app: larakube-dashboard
    spec:
      serviceAccountName: larakube-dashboard
      containers:
        - name: dashboard
          image: ghcr.io/larakube/dashboard:latest
          ports:
            - containerPort: 8080
          env:
            - name: APP_ENV
              value: production
            - name: APP_DEBUG
              value: "false"
            - name: APP_KEY
              value: base64:{{ base64_encode(random_bytes(32)) }}
            - name: KUBERNETES_HOST
              value: https://kubernetes.default.svc
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: larakube-dashboard
  namespace: larakube-system
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: larakube.dev.test
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: larakube-dashboard
                port:
                  number: 80
