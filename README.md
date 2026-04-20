<p align="center">
  <img src="resources/larakube-icon.svg" width="150" height="150" alt="LaraKube Logo">
</p>

# 🚀 LaraKube CLI
> The professional Kubernetes orchestrator for Laravel.

[![Documentation](https://img.shields.io/badge/docs-larakube.luchtech.dev-blue.svg)](https://larakube.luchtech.dev)
[![GitHub Release](https://img.shields.io/github/v/release/luchavez-technologies/larakube-cli?label=standalone)](https://github.com/luchavez-technologies/larakube-cli/releases)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

LaraKube is a high-performance Kubernetes orchestrator for Laravel, distributed as a **zero-dependency standalone binary** for Linux and macOS.

## 🌟 Key Features
- **📦 Standalone Binary**: No local PHP, Node.js, or Composer required.
- **🤖 AI-Native Interaction**: Built-in **LaraKube Chat** for orchestration via natural language.
- **🔌 Dynamic MCP Server**: Auto-scaffolding for **Gemini**, **Claude**, and **Cursor** to manage your cluster.
- **🏗 Masterpiece Blueprints**: One-command architecture for complex, real-time Laravel stacks.
- **🔒 Stability-First**: Hardened **Serversideup** configurations and automated local HTTPS (`larakube trust`).

## 📥 Quick Install (Mac/Linux)

```bash
curl -sSL https://larakube.luchtech.dev/install.sh | bash
```

## 🛠 AI-Native Usage

### 💬 LaraKube Chat
Interact with your cluster using natural language:
```bash
larakube chat
# Or single-shot:
larakube chat --query="Create a project named shop with MariaDB and Redis"
```

### 🧠 Intelligent Doctor
Automatically diagnose and heal cluster issues:
```bash
larakube doctor --ai
```

### 🔌 Global MCP Registration
Enable AI agents to manage any project directory:
```bash
larakube config:mcp --all
```

## 🏗 Common Commands
- `larakube new`: Scaffold a new architectural masterpiece.
- `larakube up`: Deploy infrastructure to your local cluster.
- `larakube stop`: Scale down pods to save resources without deleting data.
- `larakube trust`: Install Local CA for seamless HTTPS.

## 📖 Documentation
For high-context guides, recipes, and architectural deep-dives, visit the official documentation:
👉 **[https://larakube.luchtech.dev](https://larakube.luchtech.dev)**

## 📄 License
LaraKube is open-source software licensed under the MIT license.
