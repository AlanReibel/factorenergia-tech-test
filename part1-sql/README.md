# Part 1: SQL Queries & Database Schema

## 📊 Objetivo

Diseñar un esquema de base de datos relacional y escribir consultas SQL eficientes para el sistema de gestión de contratos de energía de FactorEnergia.

---

## 📋 Contenido de Esta Sección

### Archivos Principales
- **`schema.sql`** - Esquema completo de la base de datos
- **`01_queries.sql`** - Todas las consultas SQL requeridas

---

## 🗂️ Estructura de Base de Datos

El esquema incluye las siguientes tablas principales:

### **Contracts** (Contratos)
- `id` - Identificador único
- `customer_name` - Nombre del cliente
- `address` - Dirección de suministro
- `supply_point_code` (CUPS) - Código de punto de suministro
- `tariff_id` - Referencia a la tarifa
- `start_date` - Fecha de inicio del contrato
- `status` - Estado actual (active, terminated, etc)
- `country` - País (España, Portugal, etc)

### **Tariffs** (Tarifas)
- `id` - Identificador único
- `code` - Código de tarifa (ej: 2.0TD, PVPC, etc)
- `name` - Nombre descriptivo
- `price_per_kwh` - Precio por kWh
- `fixed_monthly` - Cargo fijo mensual
- `type` - Tipo de tarifa (fixed, indexed, promo, flat)

### **Meter Readings** (Lecturas de Contador)
- `id` - Identificador único
- `contract_id` - Referencia al contrato
- `reading_date` - Fecha de la lectura
- `kwh_consumed` - kWh consumidos en el período
- `status` - Estado de la lectura (validated, estimated, etc)

### **Invoices** (Facturas)
- `id` - Identificador único
- `contract_id` - Referencia al contrato
- `period_month` - Mes del período facturado
- `total_kwh` - Total de kWh consumidos
- `total_amount` - Importe total de la factura
- `status` - Estado (draft, issued, paid, etc)
- `is_portuguese` - Indica si es para contrato portugués

---

## 🔍 Consultas SQL Incluidas

Las consultas cubren los siguientes escenarios:

1. **Consultas de Lectura (SELECT)**
   - Obtener contrato con sus datos de tarifa
   - Listar lecturas de contador por contrato y mes
   - Obtener factura con detalles de contrato

2. **Consultas de Inserción (INSERT)**
   - Crear nueva factura con cálculos
   - Validar datos antes de insertar

3. **Consultas de Actualización (UPDATE)**
   - Cambiar estado de contrato
   - Actualizar estado de factura

4. **Consultas Analíticas**
   - Total de consumo por cliente
   - Ingresos por tarifa
   - Evolución de consumo mensual

---

## ⚡ Características de Seguridad

✅ **Índices** en columnas frecuentemente consultadas
✅ **Restricciones** de integridad referencial (Foreign Keys)
✅ **Validaciones** de datos en nivel de base de datos
✅ **Transacciones** para operaciones múltiples

---

## 🚀 Cómo Usar

### 1. Crear la Base de Datos
```sql
-- Ejecutar schema.sql en tu gestor SQL
source schema.sql;
-- or en SQL Server:
sqlcmd -i schema.sql
```

### 2. Ejecutar las Consultas
```sql
-- Ver todas las consultas en 01_queries.sql
source 01_queries.sql;
```

### 3. Ejemplos de Uso
Cada consulta en `01_queries.sql` incluye comentarios explicativos y ejemplos de parámetros.

---

## 📐 Normalización y Diseño

- **Normalización:** Forma Normal de Boyce-Codd (BCNF)
- **Relaciones:** 1:N entre Contracts ↔ Meter Readings, Contracts ↔ Tariffs
- **Indexes:** En `contract_id`, `reading_date`, `status`
- **Constraints:** Validación de datas, valores no negativos en precios/consumos

---

## 🔗 Relación con Otros Ejercicios

**Part 2 (Code Review)** utiliza el esquema SQL en:
- Repositorios para cargar contratos y tarifas
- Cálculo de facturas basado en estos datos

**Part 3 (API)** usa esta estructura para:
- Sincronizar contratos con reguladores externos
- Consultar datos de Auditoría

**Part 4 (Batch)** ejecuta procesos batch sobre:
- Lecturas de contador
- Generación de facturas masivas

---

## 📞 Notas Adicionales

- Las consultas son **agnósticas de BD**: funcionan en MySQL, SQL Server y PostgreSQL con pequeños ajustes
- Se proporcionan ejemplos de datos de prueba para validar el esquema
- Cada tabla tiene una columna de timestamp para auditoría

---

**Siguiente:** Continúa con [Part 2 - Code Review & Refactoring](../part2-php/README.md)
