# Formato Consumo — Backend API

API Laravel para gestión de consumo, entregas históricas, catálogos e importación controlada desde Excel.

## Entorno verificado

| Componente | Versión / Ubicación |
|------------|---------------------|
| PHP | 8.2.12 (`C:\xampp\php\php.exe`) |
| Laravel | 11.55.0 |
| MariaDB | 10.4.32 |
| Composer | 2.10.2 (`C:\xampp\php\composer.phar`) |
| Node.js (frontend) | v24.11.1 |

## Base de datos

- **Nombre:** `consumo`
- **Host:** `127.0.0.1:3306`
- **Usuario:** `root` (sin contraseña en entorno local XAMPP)

## Comandos

### Backend

```powershell
cd C:\xampp\htdocs\formato-consumo-backend

# Servidor de desarrollo
C:\xampp\php\php.exe artisan serve --host=127.0.0.1 --port=8000

# Migraciones (NO usar migrate:fresh)
C:\xampp\php\php.exe artisan migrate

# Seeders
C:\xampp\php\php.exe artisan db:seed

# Importación histórica controlada
C:\xampp\php\php.exe artisan consumo:import-excel-staging
C:\xampp\php\php.exe artisan consumo:validate-staging
C:\xampp\php\php.exe artisan consumo:promote-staging
```

### Frontend

```powershell
cd C:\xampp\htdocs\formato-consumo-frontend
npm run dev
npm run build
```

## Flujo de importación histórica

```
Excel (solo lectura)
    ↓ consumo:import-excel-staging
excel_import_staging
    ↓ consumo:validate-staging
Validación / requiere_revision
    ↓ consumo:promote-staging (solo validados)
entregas (fuente = excel_historico)
```

## Interpretación documentada

- Columna Excel **ENTREGA** → campo **`entregado_por`** (persona que realiza la entrega)
- Columna Excel **QUIEN RECIBE** → campo **`quien_recibe`**
- Registros con alias **`requiere_revision = true`** NO se promueven automáticamente
- Duplicados exactos se conservan y marcan con **`es_posible_duplicado`**
- No se inventan precios históricos

## API v1

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/v1/health` | Estado del servicio |
| GET | `/api/v1/areas` | Catálogo de áreas |
| GET | `/api/v1/categorias` | Categorías |
| GET | `/api/v1/productos` | Productos |
| GET | `/api/v1/entregas` | Entregas (filtros: fuente, area_id, fechas) |
| GET | `/api/v1/staging/summary` | Resumen de staging |
| GET | `/api/v1/staging` | Registros staging |
| POST | `/api/v1/staging/import` | Importar Excel a staging |
| POST | `/api/v1/staging/validate` | Validar staging |
| POST | `/api/v1/staging/promote` | Promover validados a entregas |
| GET | `/api/v1/staging/aliases-pendientes` | Aliases pendientes de revisión |

## Archivo Excel

Ruta de desarrollo (solo lectura):

`../formato-consumo-frontend/docs/Consumo_DESARROLLO.xlsx`

**El archivo original no debe modificarse.**
