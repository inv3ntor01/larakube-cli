apiVersion: v1
kind: Service
metadata:
  name: laravel-web
spec:
  selector:
    app: laravel-web
  ports:
    - protocol: TCP
      port: 80
      targetPort: {{ $config->getServerVariation()->containerPort() }}
  type: ClusterIP
