output "app_url" {
  description = "URL publica de la app"
  value       = "https://${azurerm_container_app.app.ingress[0].fqdn}"
}

output "app_name" {
  value = azurerm_container_app.app.name
}

output "resource_group" {
  value = azurerm_resource_group.rg.name
}
