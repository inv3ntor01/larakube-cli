apiVersion: apps/v1
kind: Deployment
metadata:
  name: garage
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: garage
  template:
    metadata:
      labels:
        app: garage
    spec:
      containers:
        - name: garage
          image: {{ $driver->getDockerImage($config) }}
          args: ["/garage", "server"]
          env:
            - name: GARAGE_RPC_SECRET
              value: "3e8d49cdaecefd63e56dc6d2f791cb60f856cd7471555b038bde1ac0751682a8"
          ports:
            - name: s3
              containerPort: 3900
            - name: web
              containerPort: 3902
            - name: admin
              containerPort: 3903
          volumeMounts:
            - name: config
              mountPath: /etc/garage.toml
              subPath: garage.toml
            - name: garage-data
              mountPath: /data
      volumes:
        - name: config
          configMap:
            name: garage-config
        - name: garage-data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-garage-pvc
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: garage-config
data:
  garage.toml: |
    metadata_dir = "/data/meta"
    data_dir = "/data/data"
    rpc_bind_addr = "[::]:3901"
    rpc_secret = "3e8d49cdaecefd63e56dc6d2f791cb60f856cd7471555b038bde1ac0751682a8"

    # Replication settings for v2.x (1 = single node local dev)
    replication_factor = 1

    [s3_api]
    s3_region = "us-east-1"
    api_bind_addr = "[::]:3900"
    root_domain = ".s3.{{ $config->getName() }}.dev.test"

    [s3_web]
    bind_addr = "[::]:3902"
    root_domain = ".s3.{{ $config->getName() }}.dev.test"
    index = "index.html"

    [admin]
    api_bind_addr = "0.0.0.0:3903"
---
apiVersion: v1
kind: Service
metadata:
  name: laravel-garage
spec:
  selector:
    app: garage
  ports:
    - name: s3
      protocol: TCP
      port: 3900
      targetPort: 3900
    - name: web
      protocol: TCP
      port: 3902
      targetPort: 3902
    - name: admin
      protocol: TCP
      port: 3903
      targetPort: 3903
  type: ClusterIP
