apiVersion: v1
kind: Namespace
metadata:
  name: {{ $namespace }}
  labels:
    larakube.io/project: {{ $config->getName() }}
    larakube.io/managed-by: larakube
