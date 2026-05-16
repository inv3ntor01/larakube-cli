apiVersion: v1
kind: Service
metadata:
  name: {{ $config->getServerVariation()->getPodName($config) }}
spec:
  selector:
    app: {{ $config->getServerVariation()->getPodName($config) }}
  ports:
    - protocol: TCP
      port: 80
      targetPort: {{ $config->getServerVariation()->containerPort() }}
  type: ClusterIP
