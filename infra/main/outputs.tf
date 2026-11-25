output "hostname" {
  value = azurerm_linux_web_app.main.default_hostname
}

output "app_name" {
  value = azurerm_linux_web_app.main.name
}

output "resource_group_name" {
  value = azurerm_resource_group.main.name
}

output "image" {
  value = local.image
}
