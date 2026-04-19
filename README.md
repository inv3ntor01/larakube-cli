# 🚀 LaraKube CLI

LaraKube is a high-performance Kubernetes orchestrator for Laravel, distributed as a **standalone binary** for Linux and macOS.

## 🌟 Key Features
- **📦 Standalone Binary**: No local PHP or Node.js required. Runs anywhere with Docker and kubectl.
- **🤖 AI-Native**: Built-in MCP server for orchestration via AI agents (like Gemini or Claude).
- **🏗 Masterpiece Blueprints**: One-command architecture for complex stacks (Meilisearch, Redis, S3).
- **💪 Stability-First**: Hardened configurations for Serversideup images and Kubernetes.

## 📥 Quick Install (Mac/Linux)

```bash
curl -sSL https://larakube.luchtech.dev/install.sh | bash
```

## 🛠 Usage

### Create a new project
```bash
larakube new my-masterpiece
```

### Deploy to local cluster
```bash
larakube up
```

### AI-Native Interaction
```bash
larakube chat
```

### AI-Native Diagnostics
```bash
larakube doctor --ai
```

## 🏗 Architecture
LaraKube uses a **Dual-Mount** strategy to execute project-specific tasks inside isolated containers, ensuring that your host machine remains clean and your environments are reproducible.

For more details, visit the [LaraKube Documentation](https://larakube.luchtech.dev).

## 📄 License
LaraKube is open-source software licensed under the MIT license.
