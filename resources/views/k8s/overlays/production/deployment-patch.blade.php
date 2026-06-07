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
{{-- Override the wait-for-deps init command per-env so it's accurate for THIS
     env's externalized services (managed/Plex). Without this, the base command —
     computed for local — waits on in-namespace mysql/redis that don't exist on a
     managed/Plex cluster, and the pod hangs in Init forever. Web waits on core
     deps; workers also wait for the web pod (it runs migrations). --}}
      initContainers:
        - name: wait-for-deps
          image: {{ $config->getName() }}:latest
{{-- JSON_UNESCAPED_SLASHES: json_encode would emit http:\/\/, and kustomize's
     patch parser rejects \/ ("unable to parse SM or JSON patch"). --}}
          command: ["sh", "-c", {!! json_encode(
              ($name === 'web'
                  ? $config->buildWaitForCommand($config->getCoreDependencies($environment))
                  : $config->buildWaitForCommand($feature->getDependencies($config, $environment), waitForWeb: true)
              ) ?: 'true', JSON_UNESCAPED_SLASHES
          ) !!}]
@endif
@endforeach
@if($config->hasFeature(\App\Enums\LaravelFeature::TASK_SCHEDULING))
{!! $first ? '' : "---\n" !!}@php($first = false)
{{-- Scheduler is a CronJob (different pod path) — same per-env override so its
     wait isn't computed for local, plus the image-pull secret it needs to pull a
     private image on a managed cluster. --}}
apiVersion: batch/v1
kind: CronJob
metadata:
  name: scheduler
spec:
  jobTemplate:
    spec:
      template:
        spec:
@if($serviceAccount = $config->getServiceAccount($environment))
          serviceAccountName: {{ $serviceAccount }}
@endif
@if($pullSecret = $config->getImagePullSecret($environment))
          imagePullSecrets:
            - name: {{ $pullSecret }}
@endif
          initContainers:
            - name: wait-for-deps
              command: ["sh", "-c", {!! json_encode($config->buildWaitForCommand(\App\Enums\LaravelFeature::TASK_SCHEDULING->getDependencies($config, $environment), waitForWeb: true) ?: 'true', JSON_UNESCAPED_SLASHES) !!}]
@endif
