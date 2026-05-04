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

### 🐘 PHP & Base Image
-   **ServerSideUp Base**: Standardized on `serversideup/php` images.
-   **Default Extensions**: The following extensions are **already bundled** in the base image. **Never** request these via `RequiresPhpExtensions` to keep Docker builds fast:
    -   `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `mysqli`, `opcache`, `openssl`, `pcntl`, `pcre`, `pdo_mysql`, `pdo_pgsql`, `redis`, `session`, `tokenizer`, `xml`, `zip`.
-   **Surgical Extensions**: Only request non-standard extensions (e.g., `gd`, `intl`, `mongodb`, `exif`) within architectural Enums.

### 📦 PHAR & Binary Standards
-   **Binary Context**: Always assume LaraKube commands are running from a compiled PHAR binary.
-   **Temporary Bridge Files**: External system tools (e.g., `openssl`, `kubectl`, `security`, `certutil`) **cannot** access files inside the PHAR (paths starting with `phar://`).
-   **The Pattern**: If a bundled resource (like a certificate or manifest template) must be processed by an external tool:
    1.  Copy the resource from its `base_path()` to a temporary location using `sys_get_temp_dir()`.
    2.  Pass the temporary path to the system tool.
    3.  Clean up (`unlink`) the temporary file after the action is complete.

### 📦 Development Wrappers
-   **Standalone**: Distributed as a Mach-O/Linux binary with embedded PHP runtime.
-   **K3D Bridge**: Automated image sideloading via `k3d image import` for local builds.
-   **Total Cleanup**: `larakube uninstall` performs a synchronized wipe of manifests, cluster resources, and Docker images.
-   **Daemon Runner**: Persistent Docker-based development environment for zero-host dependencies:
    -   **CLI**: `./php` (Daemon: `larakube-php-cli`)
    -   *Note*: Use `./php stop` to cleanup the daemon.
