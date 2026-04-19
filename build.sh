#!/bin/bash

# LaraKube Professional Builder (Standalone Edition)
# Compiles the CLI into standalone binaries with embedded PHP runtime

set -e

# Ensure we are in the CLI directory
cd "$(dirname "$0")"

BUILD_TYPE=$1
VERSION=${2:-"local"}

# Helper to run PHP via Docker
run_php() {
    export SHOW_WELCOME_MESSAGE=false
    ./php -d phar.readonly=0 "$@"
}

build_phar() {
    echo "📦 Building PHAR foundation (v$VERSION)..."
    run_php larakube app:build larakube --build-version=$VERSION
    cp builds/larakube builds/larakube.phar
}

build_standalone() {
    PLATFORM=$1
    ARCH=$2
    echo "🏗 Packaging standalone binary for $PLATFORM ($ARCH)..."
    run_php ./vendor/bin/phpacker build $PLATFORM $ARCH --src=builds/larakube.phar --dest=builds/standalone -q
}

case $BUILD_TYPE in
    "--local")
        build_phar
        
        # Detect Local Arch
        OS=$(uname -s | tr '[:upper:]' '[:lower:]')
        [ "$OS" == "darwin" ] && OS="mac"
        
        ARCH=$(uname -m)
        [ "$ARCH" == "arm64" ] && ARCH="arm"
        [ "$ARCH" == "x86_64" ] && ARCH="x64"

        build_standalone $OS $ARCH
        
        echo "🚚 Installing local binary to /usr/local/bin/larakube (requires sudo)..."
        sudo mv builds/standalone/$OS/$OS-$ARCH /usr/local/bin/larakube
        sudo chmod +x /usr/local/bin/larakube
        
        echo "✅ LaraKube v$VERSION is now live on your system!"
        ;;

    "--all")
        build_phar
        build_standalone linux x64
        build_standalone linux arm
        build_standalone mac x64
        build_standalone mac arm
        
        # Cleanup
        rm builds/larakube.phar
        
        echo "✅ All standalone binaries built in builds/standalone/"
        ;;

    *)
        echo "Usage: ./build.sh [--local | --all] [version]"
        exit 1
        ;;
esac
