apiVersion: v1
kind: Secret
metadata:
  name: laravel-secrets
type: Opaque
stringData:
  APP_KEY: {{ 'base64:'.base64_encode(random_bytes(32)) }}
  DB_PASSWORD: secretpassword
  MEILISEARCH_KEY: secretpassword
