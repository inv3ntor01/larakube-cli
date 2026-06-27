apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization

namespace: {{ $namespace }}

resources:
  - ../../base
  - namespace.yaml
  - config-patch.yaml

# Add production-specific patches here
patches:
  - path: deployment-patch.yaml

images:
  - name: {{ $config->getName() }}:latest
    newName: {{ $config->getName() }}
    newTag: {{ $environment }}-latest
