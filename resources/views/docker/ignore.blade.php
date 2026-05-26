# Source Control
.git
.github
.gitlab-ci.yml

# LaraKube Infrastructure
.infrastructure
!.infrastructure/**/local-ca.pem
!.infrastructure/conf/
.infrastructure/k8s/overlays/local/
.larakube.json

# Docker & Container Files
Dockerfile*
docker-*.yml
.dockerignore

# Secrets & Environment
.env*

# Dependencies
# We allow these to support building assets/dependencies on GitHub Runners
@if($config->getGithubActions())
# node_modules
# vendor
@else
node_modules
vendor
@endif

# Laravel Specifics
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
storage/logs/*
!storage/framework/cache/.gitignore
!storage/framework/sessions/.gitignore
!storage/framework/views/.gitignore
!storage/logs/.gitignore
