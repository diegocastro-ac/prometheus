resource "random_id" "suffix" {
  byte_length = 4
}

resource "azurerm_resource_group" "tfstate" {
  name     = "rg-prometheus-diego-tfstate-${random_id.suffix.hex}"
  location = "westus2"

  tags = {
    project = "prometheus-diego"
    purpose = "terraform-state"
  }
}

resource "azurerm_storage_account" "tfstate" {
  name                     = "stpromdiegotf${random_id.suffix.hex}"
  resource_group_name      = azurerm_resource_group.tfstate.name
  location                 = azurerm_resource_group.tfstate.location
  account_tier             = "Standard"
  account_replication_type = "LRS"
  min_tls_version          = "TLS1_2"

  tags = {
    project = "prometheus-diego"
    purpose = "terraform-state"
  }

  lifecycle {
    prevent_destroy = true
  }
}

resource "azurerm_storage_container" "tfstate" {
  name                  = "tfstate-prometheus-diego"
  storage_account_id    = azurerm_storage_account.tfstate.id
  container_access_type = "private"
}
