apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization

namespace: {{ $namespace }}

resources:
  - ../../base
  - infrastructure.yaml
@if($config->getFrontend()?->requiresNodePod())
  - node-deployment.yaml
@endif

patches:
  - path: patches.yaml
