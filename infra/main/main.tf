resource "random_id" "suffix" {
  byte_length = 4
}

locals {
  app_name     = "prometheus-diego-${random_id.suffix.hex}"
  hostname     = "${local.app_name}.azurewebsites.net"
  registry_url = "https://ghcr.io"
  image_name   = "diegocastro-ac/prometheus-diego:${var.image_tag}"
  image        = "ghcr.io/${local.image_name}"
  common_tags = {
    project = "prometheus-diego"
    repo    = "diegocastro-ac/prometheus"
  }
}

resource "azurerm_resource_group" "main" {
  name     = "rg-prometheus-diego-${random_id.suffix.hex}"
  location = var.location
  tags     = local.common_tags
}

resource "azurerm_service_plan" "main" {
  name                = "plan-prometheus-diego-${random_id.suffix.hex}"
  resource_group_name = azurerm_resource_group.main.name
  location            = azurerm_resource_group.main.location
  os_type             = "Linux"
  sku_name            = "B1"
  tags                = local.common_tags
}

resource "azurerm_linux_web_app" "main" {
  name                = local.app_name
  resource_group_name = azurerm_resource_group.main.name
  location            = azurerm_resource_group.main.location
  service_plan_id     = azurerm_service_plan.main.id
  tags                = local.common_tags

  site_config {
    application_stack {
      docker_image_name   = local.image_name
      docker_registry_url = local.registry_url
    }
    health_check_path                 = "/up"
    always_on                         = true
    ftps_state                        = "Disabled"
    minimum_tls_version               = "1.2"
    health_check_eviction_time_in_min = 10
  }

  app_settings = {
    WEBSITES_PORT                       = "8080"
    WEBSITES_CONTAINER_START_TIME_LIMIT = "600"
    WEBSITES_ENABLE_APP_SERVICE_STORAGE = "true"
    APP_ENV                             = "production"
    APP_DEBUG                           = "false"
    APP_URL                             = "https://${local.hostname}"
    APP_KEY                             = var.app_key
    DB_CONNECTION                       = "sqlite"
    DB_DATABASE                         = "/home/site/wwwroot/database/database.sqlite"
    SESSION_DRIVER                      = "database"
    CACHE_STORE                         = "database"
    QUEUE_CONNECTION                    = "database"
  }

  identity {
    type = "SystemAssigned"
  }
}
