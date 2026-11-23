# Diagrama de clases de dominio

Modelo Eloquent de `app/Models`. Cada clase muestra como máximo tres atributos y
tres métodos; el resto se resume con `...`. Las relaciones llevan el nombre del
método que las expone y la cardinalidad en sus extremos.

```plantuml
@startuml
title Modelo de dominio · Prometheus

skinparam backgroundColor #F8FAFC
skinparam shadowing false
skinparam roundCorner 10
skinparam linetype polyline
skinparam nodesep 30
skinparam ranksep 50
skinparam classAttributeIconSize 0
skinparam ArrowColor #64748B
skinparam ArrowFontColor #334155
skinparam ArrowFontSize 11
skinparam class {
  BackgroundColor #FFFFFF
  BorderColor #475569
  FontColor #0F172A
  FontSize 12
  AttributeFontSize 11
}
skinparam package {
  BackgroundColor #F1F5F9
  BorderColor #CBD5E1
  FontColor #334155
  FontSize 12
}
hide circle
hide empty members

package "Identidad" as IDENTIDAD {
  class User #DBEAFE {
    + name
    + email
    + assistant_model
    --
    + properties()
    + rentals()
    + payments()
    ...
  }
}

package "Arrendamiento" as ARRIENDO {
  class Property #DCFCE7 {
    + name
    + address
    + geography_code
    ...
    --
    + user()
    + rentals()
    + geography()
  }

  class Tenant #DCFCE7 {
    + name
    + document
    + phone_number
    ...
    --
    + user()
    + rentals()
  }

  class Rental #DCFCE7 {
    + monthly_amount
    + start_date
    + is_active
    ...
    --
    + tenant()
    + property()
    + invoices()
    ...
  }
}

package "Facturación" as FACTURACION {
  class Invoice #FEF9C3 {
    + number
    + amount
    + status
    ...
    --
    + rental()
    + payments()
    + user()
  }

  class Payment #FEF9C3 {
    + date
    + amount
    + method
    ...
    --
    + invoice()
    + rental()
    + status()
  }
}

package "IPC y reajustes" as IPC {
  class Geography #E0F2FE {
    + code
    + name
    + has_ipc
    --
    + scopeWithIpc()
  }

  class IpcSeries #E0F2FE {
    + geography_code
    + year
    + index_value
    ...
  }

  class IpcRate #FFEDD5 {
    + year
    + percentage
    + published_on
    ...
    --
    + rentalAdjustments()
  }

  class RentAdjustment #FFEDD5 {
    + new_rent
    + ipc_percentage
    + applied_pct
    ...
    --
    + rental()
    + ipcRate()
    + state()
  }
}

package "Actas e inventario" as ACTAS {
  class DeliveryAct #F3E8FF {
    + type
    + occurred_at
    + signed_at
    ...
    --
    + rental()
    + items()
    + user()
  }

  class ActItem #F3E8FF {
    + name
    + state
    + note
    ...
    --
    + deliveryAct()
    + space()
    + masterItem()
  }

  class Space #F3E8FF {
    + name
    + parent_id
    + user_id
    --
    + parent()
    + children()
    + items()
  }

  class MasterInventoryItem #F3E8FF {
    + name
    + user_id
    --
    + user()
    + actItems()
  }
}

package "Copiloto y auditoría" as COPILOTO {
  class AssistantChat #FCE7F3 {
    + title
    + user_id
    --
    + user()
    + messages()
  }

  class AssistantMessage #FCE7F3 {
    + role
    + text
    + chat_id
    ...
    --
    + chat()
    + user()
  }

  class DocumentAccess #F1F5F9 {
    + document_type
    + format
    + accessed_at
    ...
    --
    + user()
  }
}

User "1" --> "0..*" Property : properties
User "1" --> "0..*" Tenant : tenants
User "1" --> "0..*" Rental : rentals
User "1" --> "0..*" Payment : payments

Property "1" --> "0..*" Rental : rentals
Tenant "1" --> "0..*" Rental : rentals

Rental "1" --> "0..*" Invoice : invoices
Rental "1" --> "0..*" Payment : payments
Rental "1" --> "0..*" RentAdjustment : rentAdjustments
Rental "1" --> "0..*" DeliveryAct : deliveryActs

Invoice "1" --> "0..*" Payment : payments
IpcRate "1" --> "0..*" RentAdjustment : rentalAdjustments

DeliveryAct "1" --> "0..*" ActItem : items
Space "1" --> "0..*" Space : children
Space "1" --> "0..*" ActItem : items
MasterInventoryItem "1" --> "0..*" ActItem : actItems

AssistantChat "1" --> "0..*" AssistantMessage : messages

Property "0..*" --> "1" Geography : geography
Geography "1" ..> "0..*" IpcSeries : geography_code

@enduml
```

## Lectura del diagrama

- `-->` es una relación Eloquent declarada (`hasMany` / `belongsTo`); el nombre
  sobre la flecha es el método que la expone.
- `..>` es una llave compartida sin relación Eloquent (`geography_code`), que se
  usa para cruzar el catálogo de ciudades con la serie de IPC.
- Todas las entidades de primer nivel guardan `user_id` y declaran
  `user() : BelongsTo`; el diagrama dibuja solo las relaciones inversas que
  `User` define, para no llenar el gráfico de flechas repetidas.

## Fuera del alcance

`SpaceComponent`, `SpaceComposite` y `SpaceLeaf` son clases de los patrones
Composite, Iterator y Visitor, no tablas. Los DTO, objetos de valor y documentos
renderizables tampoco son modelos Eloquent.
