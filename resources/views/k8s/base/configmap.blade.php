apiVersion: v1
kind: ConfigMap
metadata:
  name: laravel-config
data:
  APP_ENV: {{ $environment }}
  APP_DEBUG: "{{ $environment === 'local' ? 'true' : 'false' }}"
@foreach($config->getAllEnvironmentVariables($environment) as $key => $value)
  {{ $key }}: "{{ $value }}"
@endforeach
