{{-- Rendered only when the env sets a serviceAccount (e.g. IRSA on EKS). The
     overlay's kustomize namespace places it in the right namespace. --}}
apiVersion: v1
kind: ServiceAccount
metadata:
  name: {{ $config->getServiceAccount($environment) }}
@if($annotations = $config->getServiceAccountAnnotations($environment))
  annotations:
@foreach($annotations as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
@endif
