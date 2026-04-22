# Contributing to LaraKube CLI

Thank you for helping us craft the future of Laravel on Kubernetes! To ensure the project remains robust and maintainable, please follow these guidelines.

## 🎨 UI Consistency

LaraKube uses a custom output system built with **Termwind**. Every command should provide clear, branded feedback so the Artisan knows which step is currently running.

### Using `LaraKubeOutput`
All command classes must use the `App\Traits\LaraKubeOutput` trait.

- **Status Updates:** Use `$this->laraKubeInfo("Message")` for standard steps.
- **Failures:** Use `$this->laraKubeError("Message")` for errors.
- **Header:** Always call `$this->renderHeader()` at the start of the `handle()` method.

## 🛠 Local Development (Professional CLI Toolkit)

LaraKube follows a strict **Zero-Host Dependency** philosophy. To ensure your development environment is consistent with our build servers, use the provided wrappers:

### 1. The Development Runner (`./php`)
Use this for running ANY LaraKube command or PHP code. It automatically bootstraps a **persistent background daemon** (`larakube-php-cli`) with `php`, `docker`, and `kubectl` pre-installed and mapped to your host for near-instant execution.

```bash
# Run chat in dev mode (Starts the daemon on first run)
./php larakube chat

# Run tinker to test your code or interact with the application context
./php larakube tinker

# Check cluster info from the warm container
./php kubectl cluster-info

# Stop the daemon to clear resources or reset state
./php stop
```

### 2. Dependency Management (`./composer`)
Always manage dependencies via the wrapper. It leverages the hot PHP CLI daemon for rapid package installation and consistent versioning.
```bash
./composer require some/package
```

### 3. The Builder (`./build`)
Use this to compile and test the standalone binary locally.
```bash
# Build and install to /usr/local/bin/larakube
./build --local
```

## 🏗 Modular Architecture (The Lego System)

LaraKube is built on a modular "Lego" philosophy. When adding functionality, prioritize creating **Hybrid Tools**.

### Creating Hybrid Tools
Hybrid tools are compatible with both the native **AI SDK** (`larakube chat`) and the global **MCP Server** (`larakube mcp`).

1. **Base Class**: Always extend `App\Ai\Tools\LaraKubeTool`.
2. **Implementation**:
   - `run(array $arguments)`: Contains the core logic (must return a string).
   - `callTool(array $arguments)`: Returns a `\Laravel\Mcp\Response` (usually calls `$this->runMcp($arguments)`).
3. **Registration**: Register new tools in both `App\Ai\Agents\LaraKubeAssistantAgent.php` and `App\Mcp\LaraKubeServer.php`.

## ✅ Development Workflow

1. **Transparency First**: Any command that modifies the repository or cluster (`new`, `init`, `add`) MUST provide an architectural preview and obtain user consent by default.
2. **Active Hooks**: Activate the professional guardrails:
   ```bash
   git config core.hooksPath .githooks
   ```
3. **Linting**: We use **Laravel Pint**. Run it via the wrapper:
   ```bash
   ./php ./vendor/bin/pint
   ```

## 🧪 Deployment Testing
When adding a feature, please test it in a real cluster or using **OrbStack / Docker Desktop** to ensure the Kubernetes manifests are valid.
