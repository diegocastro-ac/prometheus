#!/usr/bin/env bash
# Deploy de Prometheus a Azure Container Apps.
# Uso: ./deploy.sh   (desde la raiz del proyecto, en Git Bash)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

# 1. Credenciales Azure (Service Principal)
if [ -f terraform/azure-credentials.env ]; then
  # shellcheck disable=SC1091
  . terraform/azure-credentials.env
fi
: "${ARM_CLIENT_ID:?Falta ARM_CLIENT_ID (ver terraform/azure-credentials.env.example)}"
: "${ARM_CLIENT_SECRET:?Falta ARM_CLIENT_SECRET}"
: "${ARM_TENANT_ID:?Falta ARM_TENANT_ID}"
: "${ARM_SUBSCRIPTION_ID:?Falta ARM_SUBSCRIPTION_ID}"
export ARM_CLIENT_ID ARM_CLIENT_SECRET ARM_TENANT_ID ARM_SUBSCRIPTION_ID

IMAGE_REPO="${IMAGE_REPO:-isaacdavid1234/prometheus-app}"
IMAGE_TAG="${IMAGE_TAG:-latest}"
IMAGE="$IMAGE_REPO:$IMAGE_TAG"

echo "==> Build imagen $IMAGE"
docker build -t "$IMAGE" .

echo "==> Push a Docker Hub"
docker push "$IMAGE"

echo "==> Terraform init + apply"
cd terraform
terraform init
terraform apply -var "image_repository=$IMAGE_REPO" -var "image_tag=$IMAGE_TAG"

APP_URL="$(terraform output -raw app_url)"
echo ""
echo "==> Deploy completado: $APP_URL"
echo "    Healthcheck: curl -s -o /dev/null -w '%{http_code}' $APP_URL/dashboard/login"

# ACA no re-descarga 'latest' si el tag no cambia: forzar nueva revision
# con un env var de build (requiere Azure CLI logueado con el SP).
if command -v az >/dev/null 2>&1; then
  az login --service-principal -u "$ARM_CLIENT_ID" -p "$ARM_CLIENT_SECRET" --tenant "$ARM_TENANT_ID" --output none 2>/dev/null || true
  az containerapp update -n prometheus-app -g rg-prometheus \
    --set-env-vars "APP_BUILD=$(date +%s)" --output none && \
    echo "==> Nueva revision desplegada"
fi
