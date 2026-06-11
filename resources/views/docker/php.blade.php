############################################
# Base Image
############################################

# Learn more about the Server Side Up PHP Docker Images at:
# https://serversideup.net/open-source/docker-php/
FROM serversideup/php:{{ $config->getPhpVersion()->value }}-{{ $config->getServerVariation()->value }}{{ $config->getOsSuffix() }} AS base

@if($config->hasPhpExtensions())
USER root
RUN install-php-extensions {{ implode(' ', $config->getAllPhpExtensions()) }}
USER www-data
@endif

############################################
# Development Image
############################################
FROM base AS development

# We can pass USER_ID and GROUP_ID as build arguments
# to ensure the www-data user has the same UID and GID
# as the user running Docker.
ARG USER_ID
ARG GROUP_ID

# Switch to root so we can set the user ID and group ID
USER root

# Vite HMR runs in a local `node` pod built from this development image
# (`npm run dev`), so Node.js is always required here — independent of SSR.
RUN apk add --no-cache nodejs npm

# Match www-data's UID/GID to the host user (for hostPath code mounts locally).
RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID  && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID && \
    mkdir -p storage bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache

# Drop privileges back to www-data    
USER www-data

############################################
# CI image
############################################
FROM base AS ci

# Sometimes CI images need to run as root
USER root

############################################
# Assets Build Stage
# Runs `npm run build` INSIDE Docker so the compiled JS always reflects the
# correct per-environment VITE_* values (hostname, WebSocket host, etc.).
# This stage is used by the `deploy` target; pass env-specific values via
# --build-arg so the baked assets are never poisoned by the developer's local
# .env, and the host's public/build/ directory is never touched.
############################################
FROM node:lts-alpine AS assets
WORKDIR /app
COPY . .
ARG VITE_APP_URL=
ARG VITE_ASSET_URL=
ARG VITE_REVERB_HOST=
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https
ARG VITE_REVERB_APP_KEY=
RUN npm ci && npm run build

############################################
# Production Image
############################################
FROM base AS deploy

# Switch to root to fix permissions
USER root
@if($config->hasFeature(\App\Enums\LaravelFeature::SSR))

# Inertia SSR runs `node bootstrap/ssr/ssr.js` from this production image, so
# Node.js is required here. Non-SSR apps serve pre-built static assets and never
# run Node, so it's omitted to keep the image lean.
RUN apk add --no-cache nodejs npm
@endif

# Copy application files
COPY --chown=www-data:www-data . /var/www/html

# Overlay assets built inside Docker (correct VITE_* baking, no host pollution)
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

# Ensure storage and bootstrap are owned by www-data
# Sub-paths will be handled by K8s volume mounts
RUN mkdir -p storage bootstrap/cache && \
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
    mkdir -p .infrastructure/volume_data/sqlite && \
    chown -R www-data:www-data storage bootstrap/cache .infrastructure/volume_data/sqlite && \
@else
    chown -R www-data:www-data storage bootstrap/cache && \
@endif
    chmod -R 775 storage bootstrap/cache

# Drop privileges back to www-data
USER www-data
