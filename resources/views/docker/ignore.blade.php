# Source Control
.git
.github
.gitlab-ci.yml

# LaraKube Infrastructure
.infrastructure
!.infrastructure/**/local-ca.pem
.larakube.json

# Docker & Container Files
Dockerfile*
docker-*.yml
.dockerignore

# Secrets & Environment
.env*

# Dependencies
node_modules
vendor

# Laravel Specifics
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
storage/logs/*
!storage/framework/cache/.gitignore
!storage/framework/sessions/.gitignore
!storage/framework/views/.gitignore
!storage/logs/.gitignore
