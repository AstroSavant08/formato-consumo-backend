# Formato Consumo — Backend API

API Laravel para **IMPADOC / Formato de Consumo**: gestión de consumo anual, entregas, inventario operativo, homologación del histórico Excel, solicitudes de productos y autenticación mínima con Sanctum.

**Repositorio frontend:** [formato-consumo-frontend](https://github.com/AstroSavant08/formato-consumo-frontend)

---

## Entorno verificado

| Componente | Versión / Ubicación |
|------------|---------------------|
| PHP | 8.2.12 (`C:\xampp\php\php.exe`) |
| Laravel | 11.x |
| Laravel Sanctum | 4.x |
| MariaDB / MySQL | 10.4.x |
| Composer | 2.x |
| Node.js (frontend) | v24.x |

## Base de datos

- **Nombre:** `consumo`
- **Host:** `127.0.0.1:3306`
- **Usuario:** `root` (sin contraseña en XAMPP local)

> **Importante:** No ejecutar `migrate:fresh` ni modificar datos de producción sin autorización del equipo. Los productos **2** y **28** tienen inventario real protegido — no usarlos en pruebas manuales destructivas.

---

## Instalación rápida

```powershell
cd C:\xampp\htdocs\formato-consumo-backend

# Dependencias
C:\xampp\php\php.exe C:\xampp\php\composer.phar install

# Configuración
copy .env.example .env
C:\xampp\php\php.exe artisan key:generate

# Migraciones (incrementales, NO fresh)
C:\xampp\php\php.exe artisan migrate

# Seeders (solo entorno local/dev)
C:\xampp\php\php.exe artisan db:seed

# Servidor
C:\xampp\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

### Usuarios de desarrollo (`UserSeeder`)

El seeder solo corre en entornos `local` y `testing`:

| Email | Rol | Password |
|-------|-----|----------|
| `solicitante@impadoc.test` | solicitante | `password` |
| `supervisor@impadoc.test` | supervisor | `password` |

> No ejecutar seeders sobre la BD real de producción sin autorización explícita.

### Tests

```powershell
C:\xampp\php\php.exe artisan test
```

Suite actual: **206 tests** (inventario, entregas, staging, solicitudes, auth, unidades, Block 4).

---

## Módulos implementados

### Core (desde el inicio del proyecto)

- Catálogos: áreas, categorías, productos (operativos e históricos Excel).
- **Consumo anual** (`consumo-anio/{anio}`): planificación mensual por producto.
- **Formato pedido anual** (`formato-pedido/{anio}`): pedidos programados.
- **Entregas operativas** (`POST /entregas`): descuenta inventario, valida unidades, soporta `solicitud_id` opcional.
- Consulta de entregas con filtros (fuente, área, fechas).

### Block 4 — Histórico Excel (intacto, no modificar sin autorización)

Pipeline staging → validación → homologación → promoción a entregas `excel_historico`:

```
Excel (solo lectura)
    ↓ POST /staging/import  o  consumo:import-excel-staging
excel_import_staging
    ↓ homologación manual / bulk
    ↓ POST /staging/validate-selected
    ↓ POST /staging/promote-selected
entregas (fuente = excel_historico)
```

**Interpretación documentada del Excel:**

- Columna **ENTREGA** → `entregado_por`
- Columna **QUIEN RECIBE** → `quien_recibe`
- Alias `requiere_revision = true` → no se promueve automáticamente
- Duplicados exactos → `es_posible_duplicado`
- No se inventan precios históricos

### Block 5 — Inventario operativo

- Stock: físico, reserva administrativa, comprometido, mínimo, disponible.
- Entradas, ajustes, movimientos de inventario.
- Alertas por stock bajo mínimo (semáforo amarillo operativo).
- Conversión de unidades: ML↔L, G↔KG en entradas, entregas y solicitudes.
- Validación de unidad compatible con `unidad_default` del producto.

### Block 5.10 — Solicitudes → stock comprometido

- CRUD de solicitudes con detalle multilínea.
- Flujo de estados: `pendiente` → `en_revision` → `aprobada` → `entregada` (+ `rechazada` / `cancelada`).
- **Aprobar** incrementa `stock_comprometido` (no toca `stock_fisico`).
- Entrega vinculada (`solicitud_id`) libera comprometido y descuenta físico sin doble descuento.
- `stock_reserva` es administrativa — no se auto-incrementa por solicitudes.

### Block 6.2 — Autenticación mínima (Sanctum)

- `POST /auth/login` → token Bearer.
- `GET /auth/me`, `POST /auth/logout` (requieren token).
- Escritura de solicitudes protegida con `auth:sanctum`.
- `usuario_id` y `aprobado_por` se derivan del usuario autenticado en el servidor (no del body).

### Pendiente (no implementado aún)

- UI frontend del módulo Solicitudes (Block 6.3+).
- Semáforo por promedio histórico de consumo.
- Precios históricos automáticos.
- Panel dashboard global.
- Protección auth en rutas de staging (riesgo documentado).

---

## API v1 — Referencia

Base URL: `http://127.0.0.1:8000/api/v1`

### Auth

| Método | Endpoint | Auth | Descripción |
|--------|----------|------|-------------|
| POST | `/auth/login` | No | `{ email, password }` → `{ token, user }` |
| GET | `/auth/me` | Bearer | Usuario autenticado |
| POST | `/auth/logout` | Bearer | Revoca token actual |

### Catálogos y planes

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/health` | Estado del servicio |
| GET | `/areas` | Áreas activas |
| GET | `/categorias` | Categorías |
| GET | `/productos` | Productos (`?historico=false` para operativos) |
| GET | `/consumo-anio/{anio}` | Plan consumo anual |
| PUT | `/consumo-anio/{anio}` | Actualizar plan consumo |
| GET | `/formato-pedido/{anio}` | Plan pedido anual |
| PUT | `/formato-pedido/{anio}` | Actualizar plan pedido |

### Entregas

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/entregas` | Listado (filtros: fuente, area_id, fechas) |
| POST | `/entregas` | Entrega operativa (`solicitud_id` opcional) |

### Inventario y alertas

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/inventarios` | Listado de inventarios |
| GET | `/inventarios/{producto}` | Inventario de un producto |
| POST | `/inventarios/{producto}/inicial` | Crear inventario inicial |
| POST | `/inventarios/{producto}/entrada` | Registrar entrada |
| POST | `/inventarios/{producto}/ajuste` | Registrar ajuste |
| GET | `/movimientos-inventario` | Historial de movimientos |
| GET | `/alertas` | Alertas de inventario |

### Solicitudes

| Método | Endpoint | Auth | Descripción |
|--------|----------|------|-------------|
| GET | `/solicitudes` | No | Listado (filtros: estado, area_id) |
| GET | `/solicitudes/{id}` | No | Detalle |
| POST | `/solicitudes` | Bearer | Crear (usuario = autenticado) |
| PATCH | `/solicitudes/{id}` | Bearer | Actualizar |
| POST | `/solicitudes/{id}/aprobar` | Bearer | Aprobar → compromete stock |
| POST | `/solicitudes/{id}/rechazar` | Bearer | Rechazar |
| POST | `/solicitudes/{id}/cancelar` | Bearer | Cancelar (libera comprometido si aplica) |

**Contrato POST /solicitudes** (no enviar `usuario_id`):

```json
{
  "area_id": 1,
  "fecha": "2026-07-27",
  "justificacion": "...",
  "observaciones": "...",
  "detalles": [
    { "producto_id": 1, "cantidad": 10, "unidad": "UND", "precio_unitario": 0 }
  ]
}
```

### Staging / homologación histórica

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/staging/summary` | Resumen del staging |
| GET | `/staging/revision` | Cola de revisión enriquecida |
| GET | `/staging` | Registros staging |
| GET | `/staging/aliases-pendientes` | Aliases pendientes |
| GET | `/staging/{id}/homologacion` | Homologación de una fila |
| POST | `/staging/{id}/homologacion` | Homologar fila individual |
| POST | `/staging/homologaciones/bulk` | Homologación masiva |
| POST | `/staging/import` | Importar Excel a staging |
| POST | `/staging/validate` | Validar todo el staging |
| POST | `/staging/validate-selected` | Validar filas seleccionadas |
| POST | `/staging/promote` | Promover validados |
| POST | `/staging/promote-selected` | Promover seleccionados |

> Rutas staging **sin auth** actualmente — uso interno/dev.

---

## Comandos Artisan (histórico)

```powershell
C:\xampp\php\php.exe artisan consumo:import-excel-staging
C:\xampp\php\php.exe artisan consumo:validate-staging
C:\xampp\php\php.exe artisan consumo:promote-staging
```

## Archivo Excel de referencia

Ruta en el repo frontend:

`../formato-consumo-frontend/docs/FORMATO CONSUMO Y PEDIDO PDTOS ASEO 2026.xlsx`

**El archivo original no debe modificarse.**

---

## Estructura relevante

```
app/
  Http/Controllers/Api/V1/   # Controllers REST
  Http/Requests/             # Validación de entrada
  Http/Resources/            # Serialización JSON
  Models/                    # Eloquent
  Services/                  # Lógica de negocio
  Support/                   # Conversiones, normalización
tests/Feature/               # Tests de integración API
database/migrations/       # Esquema BD
database/seeders/            # Datos iniciales
```

---

## Reglas del equipo

1. Backend es la **autoridad** en stock, validaciones y transiciones de estado.
2. No relajar validaciones de inventario ni solicitudes desde el frontend.
3. No modificar entregas históricas del Block 4 sin autorización.
4. No insertar/modificar datos en BD real sin autorización explícita.
5. Antes de trabajar: `git pull`. Al terminar: `git commit` + `git push`.

---

## Documentación adicional (frontend)

- `formato-consumo-frontend/docs/PLAN_EJECUCION_REQUERIMIENTOS.md` — roadmap por fases.
- `formato-consumo-frontend/docs/AUDITORIA_CUMPLIMIENTO_REQUERIMIENTOS.md` — auditoría inicial (jul 2026, parcialmente desactualizada).
