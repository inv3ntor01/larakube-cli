name: Deploy to Kubernetes

on:
  push:
    branches: [ "main" ]

env:
  REGISTRY: ghcr.io
  IMAGE_NAME: ${{ github.repository }}

jobs:
  build-and-deploy:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write

    steps:
      - name: Checkout repository
        uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: 'npm'

      - name: Create .env file
        run: |
          echo "${{ secrets.PRODUCTION_ENV_FILE_BASE64 }}" | base64 -d > .env

      - name: Build Assets
        run: |
          npm install
          npm run build

      - name: Log in to the Container registry
        uses: docker/login-action@v3
        with:
          registry: ${{ env.REGISTRY }}
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Extract metadata (tags, labels) for Docker
        id: meta
        uses: docker/metadata-action@v5
        with:
          images: ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}
          tags: |
            type=sha,format=long
            type=raw,value=latest

      - name: Build and push Docker image
        uses: docker/build-push-action@v5
        with:
          context: .
          file: Dockerfile.php
          push: true
          tags: ${{ steps.meta.outputs.tags }}
          labels: ${{ steps.meta.outputs.labels }}
          target: deploy

      - name: Set Kubernetes context
        uses: azure/k8s-set-context@v3
        with:
          method: kubeconfig
          kubeconfig: ${{ secrets.KUBECONFIG }}

      - name: Deploy to Kubernetes
        run: |
          # 1. Create/Update ConfigMap and Secret from .env
          kubectl create configmap laravel-config -n {{ $config->getName() }}-production --from-env-file=.env --dry-run=client -o yaml | kubectl apply -f -
          kubectl create secret generic laravel-secrets -n {{ $config->getName() }}-production --from-env-file=.env --dry-run=client -o yaml | kubectl apply -f -

          # 2. Use kustomize to set the new image tag and deploy
          cd .infrastructure/k8s/overlays/production
          kubectl kustomize . | sed "s|image: {{ $config->getName() }}:latest|image: ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}:${{ github.sha }}|g" | kubectl apply -f -
