variable "subscription_id" {
  description = "Azure Subscription ID. Si se deja null se usa ARM_SUBSCRIPTION_ID."
  type        = string
  default     = null
}

variable "location" {
  description = "Region de Azure (la suscripcion tiene politica de regiones permitidas)"
  type        = string
  default     = "chilecentral"
}

variable "resource_group_name" {
  type    = string
  default = "rg-prometheus"
}

variable "app_name" {
  description = "Nombre de la Container App (tambien define el FQDN y APP_URL)"
  type        = string
  default     = "prometheus-app"
}

variable "environment_name" {
  type    = string
  default = "prometheus-env"
}

variable "image_repository" {
  description = "Imagen en Docker Hub (repo publico)"
  type        = string
  default     = "isaacdavid1234/prometheus-app"
}

variable "image_tag" {
  type    = string
  default = "latest"
}

variable "min_replicas" {
  description = "0 = scale-to-zero (cold start). Subir a 1 si se quiere siempre encendida."
  type        = number
  default     = 0
}

variable "max_replicas" {
  description = "SQLite no admite multiples replicas escribiendo a la vez"
  type        = number
  default     = 1
}
