apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization

namespace: {{ $namespace }}

resources:
  - ../../base
  - namespace.yaml

# Add production-specific patches here
patches:
  - path: deployment-patch.yaml
