{{-- DigitalOcean Kubernetes (DOKS) managed cluster.
     Rendered by cloud:create into ~/.larakube/tofu/<stack>/main.tf.
     The do_token is supplied at runtime via TF_VAR_do_token (never written here). --}}
terraform {
  required_providers {
    digitalocean = {
      source  = "digitalocean/digitalocean"
      version = "~> 2.0"
    }
  }
}

variable "do_token" {
  type      = string
  sensitive = true
}

provider "digitalocean" {
  token = var.do_token
}

# Pin to a currently-supported patch of the chosen minor (e.g. "1.31.").
data "digitalocean_kubernetes_versions" "current" {
  version_prefix = "{{ $versionPrefix ?? '' }}"
}

resource "digitalocean_kubernetes_cluster" "larakube" {
  name    = "{{ $clusterName }}"
  region  = "{{ $region }}"
  version = data.digitalocean_kubernetes_versions.current.latest_version

  tags = ["larakube"]

  node_pool {
    name       = "{{ $clusterName }}-pool"
    size       = "{{ $size }}"
    node_count = {{ (int) ($nodeCount ?? 2) }}
  }
}

output "context" {
  value = "do-{{ $region }}-{{ $clusterName }}"
}

output "cluster_id" {
  value = digitalocean_kubernetes_cluster.larakube.id
}

output "endpoint" {
  value = digitalocean_kubernetes_cluster.larakube.endpoint
}

# Raw kubeconfig for the cluster — consumed by cloud:create to merge locally.
output "kubeconfig" {
  value     = digitalocean_kubernetes_cluster.larakube.kube_config[0].raw_config
  sensitive = true
}
