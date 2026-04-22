#!/bin/bash

# LaraKube Professional Development Runner (Persistent Daemon Version)
# Supports PHP, Docker, and Kubectl for orchestration development

CLI_DIR=$(cd "$(dirname "$0")" && pwd)
PROJECT_DIR=$(pwd)
USER_ID=$(id -u)
GROUP_ID=$(id -g)
HOST_HOME="$HOME"
DOCKER_SOCK="/var/run/docker.sock"
CONTAINER_NAME="larakube-php-cli"

# Detect Architecture for tool installation
ARCH=$(uname -m)
[ "$ARCH" = "arm64" ] && K8S_ARCH="arm64" || K8S_ARCH="amd64"
[ "$ARCH" = "arm64" ] && DOCKER_ARCH="aarch64" || DOCKER_ARCH="x86_64"

# Handle "stop" command to cleanup the daemon
if [ "$1" = "stop" ]; then
    echo "🛑 Stopping LaraKube PHP CLI Daemon..."
    docker stop "$CONTAINER_NAME" > /dev/null 2>&1 || true
    docker rm "$CONTAINER_NAME" > /dev/null 2>&1 || true
    exit 0
fi

# Ensure the daemon is running
if ! docker ps --format '{{.Names}}' | grep -q "^$CONTAINER_NAME$"; then
    echo "🚀 Starting LaraKube PHP CLI Daemon..."
    
    # Remove any stale container
    docker rm -f "$CONTAINER_NAME" > /dev/null 2>&1 || true

    # Start the container in background
    docker run -d \
        --name "$CONTAINER_NAME" \
        -v "$PROJECT_DIR":/app \
        -v "$CLI_DIR":/larakube \
        -v "$HOST_HOME":/home/php \
        -v "$DOCKER_SOCK":/var/run/docker.sock \
        -w /app \
        --user 0:0 \
        -e HOME=/home/php \
        -e USER_ID=$USER_ID \
        -e GROUP_ID=$GROUP_ID \
        -e COMPOSER_ALLOW_SUPERUSER=1 \
        -e SHOW_WELCOME_MESSAGE=false \
        --entrypoint /bin/sh \
        serversideup/php:8.4-cli \
        -c "tail -f /dev/null" > /dev/null

    # Install tools once (Silently)
    echo "🛠 Installing tools (kubectl, docker) in daemon..."
    docker exec "$CONTAINER_NAME" /bin/sh -c "
        if ! command -v kubectl > /dev/null; then
            curl -LOs \"https://dl.k8s.io/release/\$(curl -L -s https://dl.k8s.io/release/stable.txt)/bin/linux/$K8S_ARCH/kubectl\"
            chmod +x kubectl && mv kubectl /usr/local/bin/ > /dev/null 2>&1
        fi
        if ! command -v docker > /dev/null; then
            curl -fsSLs https://download.docker.com/linux/static/stable/$DOCKER_ARCH/docker-27.3.1.tgz | tar xz -C /tmp/ && mv /tmp/docker/docker /usr/local/bin/ > /dev/null 2>&1
        fi
    "
fi

# Determine if we should run interactively
INTERACTIVE="-i"
[ -t 0 ] && INTERACTIVE="-it"

# Execute the command
docker exec $INTERACTIVE "$CONTAINER_NAME" /bin/sh -c "
    # Execution logic
    # If the first arg is php, shift and run php with the rest
    if [ \"\$1\" = \"php\" ]; then
        shift
        php \"\$@\"
    # If it's a native tool, run it directly
    elif [ \"\$1\" = \"docker\" ] || [ \"\$1\" = \"kubectl\" ] || [ \"\$1\" = \"composer\" ]; then
        \"\$@\"
    # Otherwise, assume the user wants to run a PHP file in the current dir (like larakube)
    else
        php \"\$@\"
    fi
" -- "$@"
