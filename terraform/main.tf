locals {
  image = "${var.image_repository}:${var.image_tag}"

  # .env completo viaja como secret y install.sh lo decodifica a /var/www/html/.env.
  # Las variables de entorno reales del container app tienen prioridad sobre el archivo.
  app_env_file_b64 = filebase64("${path.module}/../.env")

  app_url = "https://${var.app_name}.${azurerm_container_app_environment.env.default_domain}"
}

resource "random_string" "suffix" {
  length  = 8
  upper   = false
  special = false
}

resource "azurerm_resource_group" "rg" {
  name     = var.resource_group_name
  location = var.location
}

resource "azurerm_log_analytics_workspace" "law" {
  name                = "${var.app_name}-law"
  location            = azurerm_resource_group.rg.location
  resource_group_name = azurerm_resource_group.rg.name
  sku                 = "PerGB2018"
  retention_in_days   = 30
}

resource "azurerm_container_app_environment" "env" {
  name                       = var.environment_name
  location                   = azurerm_resource_group.rg.location
  resource_group_name        = azurerm_resource_group.rg.name
  log_analytics_workspace_id = azurerm_log_analytics_workspace.law.id
}

# --- Persistencia SQLite: Azure Files ---

resource "azurerm_storage_account" "st" {
  name                     = "st${random_string.suffix.result}"
  resource_group_name      = azurerm_resource_group.rg.name
  location                 = azurerm_resource_group.rg.location
  account_tier             = "Standard"
  account_replication_type = "LRS"
  account_kind             = "StorageV2"

  min_tls_version                 = "TLS1_2"
  allow_nested_items_to_be_public = false
}

resource "azurerm_storage_share" "appdata" {
  name               = "appdata"
  storage_account_id = azurerm_storage_account.st.id
  quota              = 5
}

# Vincula el file share al Container Apps Environment (requerido para volúmenes AzureFile)
resource "azurerm_container_app_environment_storage" "appdata" {
  name                         = "appdata"
  container_app_environment_id = azurerm_container_app_environment.env.id
  account_name                 = azurerm_storage_account.st.name
  share_name                   = azurerm_storage_share.appdata.name
  access_key                   = azurerm_storage_account.st.primary_access_key
  access_mode                  = "ReadWrite"
}

# --- App ---

resource "azurerm_container_app" "app" {
  name                         = var.app_name
  container_app_environment_id = azurerm_container_app_environment.env.id
  resource_group_name          = azurerm_resource_group.rg.name
  revision_mode                = "Single"
  max_inactive_revisions       = 0

  ingress {
    external_enabled = true
    target_port      = 80
    transport        = "http"

    traffic_weight {
      latest_revision = true
      percentage      = 100
    }
  }

  secret {
    name  = "app-env-file"
    value = local.app_env_file_b64
  }

  template {
    min_replicas = var.min_replicas
    max_replicas = var.max_replicas

    container {
      name   = "prometheus"
      image  = local.image
      cpu    = 0.5
      memory = "1Gi"

      # Overrides: las env vars reales ganan sobre el .env decodificado
      env {
        name  = "APP_ENV_FILE_B64"
        secret_name = "app-env-file"
      }
      env {
        name  = "APP_ENV"
        value = "production"
      }
      env {
        name  = "APP_DEBUG"
        value = "false"
      }
      env {
        name  = "APP_URL"
        value = local.app_url
      }
      env {
        name  = "DB_DATABASE"
        value = "/var/www/html/database/database.sqlite"
      }
      # SQLite sobre Azure Files (SMB) tiene locking fragil: session/cache/queue
      # en drivers no-database reducen drasticamente las escrituras concurrentes
      env {
        name  = "SESSION_DRIVER"
        value = "file"
      }
      env {
        name  = "CACHE_STORE"
        value = "file"
      }
      env {
        name  = "QUEUE_CONNECTION"
        value = "sync"
      }

      volume_mounts {
        name = "appdata"
        path = "/mnt/appdata"
      }
    }

    volume {
      name          = "appdata"
      storage_type  = "AzureFile"
      storage_name  = azurerm_container_app_environment_storage.appdata.name
      mount_options = "dir_mode=0777,file_mode=0666"
    }
  }
}
