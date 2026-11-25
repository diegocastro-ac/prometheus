output "hostname" {
  value = azurerm_public_ip.main.fqdn
}

output "public_ip" {
  value = azurerm_public_ip.main.ip_address
}

output "vm_name" {
  value = azurerm_linux_virtual_machine.main.name
}

output "resource_group_name" {
  value = azurerm_resource_group.main.name
}

output "image" {
  value = local.image
}
