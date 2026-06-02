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
@if($serviceAccount = $config->getServiceAccount($environment))
      serviceAccountName: {{ $serviceAccount }}
@endif
      containers:
        - name: php
          imagePullPolicy: IfNotPresent
@if($pullSecret = $config->getImagePullSecret($environment))
      imagePullSecrets:
        - name: {{ $pullSecret }}
@endif
@if($name === 'web')
      initContainers:
        - name: wait-for-deps
          image: {{ $config->getName() }}:latest
          command: ["sh", "-c", {!! json_encode($config->buildWaitForCommand($config->getCoreDependencies($environment)) ?: 'true') !!}]
@endif
@endif
@endforeach
