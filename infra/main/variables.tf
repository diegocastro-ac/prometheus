variable "location" {
  description = "Región de Azure."
  type        = string
  default     = "westus2"
}

variable "image_tag" {
  description = "SHA del commit desplegado; tag de la imagen en GHCR."
  type        = string
}

variable "ssh_public_key" {
  description = "Clave pública SSH para el administrador de la VM. Nunca se guarda la privada."
  type        = string
}
