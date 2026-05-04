apiVersion: v1
kind: ConfigMap
metadata:
  name: laravel-config
data:
  APP_ENV: production
  APP_DEBUG: "false"
@foreach($config->getAllEnvironmentVariables() as $key => $value)
  {{ $key }}: "{{ $value }}"
@endforeach
