#!/bin/bash

# LaraKube Professional Development Runner
# Supports PHP, Docker, and Kubectl for orchestration development

CLI_DIR=$(cd "$(dirname "$0")" && pwd)
PROJECT_DIR=$(pwd)
USER_ID=$(id -u)
GROUP_ID=$(id -g)
HOST_HOME="$HOME"
DOCKER_SOCK="/var/run/docker.sock"

# Detect Architecture for tool installation
ARCH=$(uname -m)
[ "$ARCH" = "arm64" ] && K8S_ARCH="arm64" || K8S_ARCH="amd64"
[ "$ARCH" = "arm64" ] && DOCKER_ARCH="aarch64" || DOCKER_ARCH="x86_64"

docker run --rm -it \
    -v "$PROJECT_DIR":/app \
    -v "$CLI_DIR":/larakube \
    -v "$HOST_HOME":/home/php \
    -v "$DOCKER_SOCK":/var/run/docker.sock \
    -w /app \
    --user 0:0 \
    -e HOME=/home/php \
    -e USER_ID=$USER_ID \
    -e GROUP_ID=$GROUP_ID \
    -e SHOW_WELCOME_MESSAGE=false \
    --entrypoint /bin/sh \
    serversideup/php:8.4-cli \
    -c "
        # 1. Install kubectl (Silently)
        if ! command -v kubectl > /dev/null; then
            curl -LOs \"https://dl.k8s.io/release/\$(curl -L -s https://dl.k8s.io/release/stable.txt)/bin/linux/$K8S_ARCH/kubectl\"
            chmod +x kubectl && mv kubectl /usr/local/bin/ > /dev/null 2>&1
        fi
        # 2. Install docker CLI (Silently)
        if ! command -v docker > /dev/null; then
            curl -fsSLs https://download.docker.com/linux/static/stable/$DOCKER_ARCH/docker-27.3.1.tgz | tar xz -C /tmp/ && mv /tmp/docker/docker /usr/local/bin/ > /dev/null 2>&1
        fi
        
        # 3. Execution logic
        # If the first arg is php, shift and run php with the rest
        if [ \"\$1\" = \"php\" ]; then
            shift
            php \"\$@\"
        # If it's a native tool, run it directly
        elif [ \"\$1\" = \"docker\" ] || [ \"\$1\" = \"kubectl\" ]; then
            \"\$@\"
        # Otherwise, assume the user wants to run a PHP file in the current dir (like larakube)
        else
            php \"\$@\"
        fi
    " -- "$@"
