# speckit.plan: Environment-First Configuration Schema

## 🎯 Objective
Refactor the `.larakube.json` DNA structure to support environment-specific overrides. This eliminates top-level global flags and allows for distinct configurations (Ingress, Managed Services, Hosts) for `local`, `staging`, and `production`.

## 🧩 Architectural Implementation

### 1. The New Schema
The `.larakube.json` will be updated to include an `environments` map:

```json
{
  "name": "project-name",
  "environments": {
    "local": {
      "ingress": "traefik",
      "managed": []
    },
    "production": {
      "ingress": "aws-alb",
      "managed": ["postgres", "redis"],
      "hosts": {
        "web": "app.example.com",
        "reverb": "ws.example.com"
      }
    }
  }
}
```

### 2. Core Engine Refactor
- **`app/Data/ConfigData.php`**: 
    - Create a new `EnvironmentData` Spatie Data class.
    - Change `$environments` from `string[]` to `Map<string, EnvironmentData>`.
    - Update `getServiceHost()` and `getIngressController()` to resolve values from the currently active environment context.
- **`app/Traits/GathersInfrastructureConfig.php`**:
    - Update the `init` wizard to populate these per-environment buckets during the CLI setup.

### 3. Template Updates
- Update all `.blade.php` manifests to use the new contextual getters (e.g., `$config->getIngress($env)`) instead of checking top-level project flags.

## ✅ Action Plan
1. Define the `EnvironmentData` class.
2. Implement migration logic in `ConfigData` to convert old flat-schema files to the new nested format.
3. Update the `larakube init` flow.
4. Refactor manifest templates to be "Environment Aware."
