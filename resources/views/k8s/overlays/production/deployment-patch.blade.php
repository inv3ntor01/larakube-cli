@php($first = true)
@foreach(['web', 'horizon', 'queues', 'reverb'] as $name)
@php($feature = \App\Enums\LaravelFeature::fromPodName($name))
@if($name === 'web' || ($feature && $config->hasFeature($feature)))
{!! $first ? '' : "---\n" !!}@php($first = false)
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $name }}
spec:
  replicas: {{ $config->getStrategy($environment) === \App\Enums\DeploymentStrategy::MULTI_NODE_HA ? 2 : 1 }}
  template:
    spec:
      containers:
        - name: php
          imagePullPolicy: Always
      imagePullSecrets:
        - name: ghcr-login
@endif
@endforeach
