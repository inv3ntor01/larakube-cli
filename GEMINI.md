# LaraKube CLI Context (GEMINI.md)

This file provides the definitive technical and architectural standards for the LaraKube CLI.

## 🚀 Core Engine: `orchestrateProjectScaffolding`
Located in `GeneratesProjectInfrastructure.php`, this engine coordinates:
-   **Blueprint Resilience**: Automatically backs up `.larakube.json` to a cluster Secret (`larakube-blueprint`). The `heal` command can restore this DNA if the local file is lost.
-   **Architecture-by-Flag**: Supports surgical CLI flags (`--frankenphp`, `--mysql`, `--meilisearch`, `--reverb`) to bypass the wizard.
-   **Server Variations**: Dynamically maps ports and schemes based on the server choice (FrankenPHP: 8080/http, Nginx/Apache: 8443/https).
-   **Stability Guards**: Databases use `strategy: type: Recreate` to prevent volume corruption during updates.

## 🤖 AI-Native Orchestration
LaraKube is designed for the age of AI agents:
1.  **Autonomous Healing**: `larakube doctor --ai` uses the LaraKube Tool SDK for automated cluster recovery.
2.  **MCP Integration**: Auto-scaffolds configurations for Gemini, Claude, and Cursor.
3.  **Discovery**: AI tools use the `InteractsWithLaraKubeCli` trait to learn flags in real-time via `larakube help`.

## 🛠 Technical Standards

### 🌐 Networking & Ingress
-   **Local Domains**: Standardized on **`.dev.test`**.
-   **Traefik v3**: Dedicated `traefik:*` suite for managing the cluster-wide networking stack.
-   **Wildcard SSL**: Automated provisioning of LaraKube Local CA certificates via `larakube trust`.
-   **Host Mapping**: `InteractsWithHosts` prioritizes `127.0.0.1` for Mac/Windows and LoadBalancer IPs for Linux.

### 📦 Development Wrappers
-   **Standalone**: Distributed as a Mach-O/Linux binary with embedded PHP runtime.
-   **K3D Bridge**: Automated image sideloading via `k3d image import` for local builds.
-   **Total Cleanup**: `larakube uninstall` performs a synchronized wipe of manifests, cluster resources, and Docker images.
-   **Daemon Runner**: Persistent Docker-based development environment for zero-host dependencies:
    -   **CLI**: `./php` (Daemon: `larakube-php-cli`)
    -   *Note*: Use `./php stop` to cleanup the daemon.
