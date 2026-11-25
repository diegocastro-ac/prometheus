variable "location" {
  description = "Región de Azure."
  type        = string
  default     = "eastus"
}

variable "image_tag" {
  description = "SHA del commit desplegado; tag de la imagen en GHCR."
  type        = string
}

variable "app_key" {
  description = "APP_KEY de Laravel. Nunca almacenar en Git ni en logs."
  type        = string
  sensitive   = true
}
