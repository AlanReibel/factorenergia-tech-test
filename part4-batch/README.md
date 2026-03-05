# Part 4: Batch Processing & Scaling

## 🎯 Objetivo

Implementar un proceso **nocturno de generación de facturas en batch** para procesar miles de contratos de forma eficiente, sin duplicados, con manejo de errores robusto y notificaciones.

---

## 📋 Requerimientos (del Ejercicio 4)

### Contexto
- **~10,000 contratos activos** a procesar cada noche
- Ejecución a las **03:00 UTC**
- Debe manejar fallos sin detener el proceso completo
- Prevenir duplicados
- Loguear resultados
- Enviar resumen por email

### Ejercicio 4.1 - Implementación
- ✅ Trigger: Symfony Command (Console/CLI)
- ✅ Flujo: Orquestación con batches de 100 contratos
- ✅ Error handling: Excepciones por contrato, no detiene batch
- ✅ Prevención duplicados: Verificación en DB antes de crear

### Ejercicio 4.2 - Escalabilidad
- ✅ 100,000 contratos: Procesamiento en chunks con memory management
- ✅ Timeout BD: Índices en `contracts.id`, `meter_readings.contract_id`
- ✅ Horario: Evitar horas de negocio (contención de recursos)

---

## 🏗️ Arquitectura

```
┌─────────────────────────────────────┐
│  Cron (03:00 UTC)                   │
│  php bin/console invoices:generate  │
└───────────────┬─────────────────────┘
                ↓
     ┌──────────────────────────┐
     │ GenerateInvoicesCommand  │  ← CLI Entry Point
     │ - Parsea argumentos      │
     │ - Orquesta el batch      │
     │ - Muestra progreso       │
     └─────────┬────────────────┘
               ↓
     ┌──────────────────────────┐
     │ BatchInvoiceGenerator    │  ← Procesamiento
     │ - Carga contratos por batch  │
     │ - Procesa cada uno       │
     │ - Recolecta estadísticas │
     └──────┬───────────────────┘
            ↓
  ┌─────────┴────────────┐
  ↓                      ↓
InvoiceService       TariffCalculators
(usa code de         (Strategy Pattern)
 Part 2)
  └──────────┬──────────┘
             ↓
        Repository Layer
        (BD Access)
```

---

## 📂 Estructura de Carpetas

```
part4-batch/
├── README.md ← Estás aquí
├── Command/
│   └── GenerateInvoicesCommand.php
│       - Entry point CLI
│       - Manejo de argumentos
│       - Salida en consola
│       - Gestión de errores de alto nivel
│
└── Service/
    ├── BatchInvoiceGenerator.php
    │   - Carga contratos en chunks
    │   - Procesa cada contrato
    │   - Recolecta estadísticas
    │   - Manejo de errores individual
    │
    └── SummaryEmailer.php
        - Construye email HTML
        - Envía a administradores
        - Notificaciones de error

NOTA: Usa InvoiceService y Repository de part2-php
```

---

## 🔑 Conceptos Clave

### 1. **Batch Processing (BATCH_SIZE = 100)**
```php
// Procesa contratos en chunks para no cargar toda la BD en memoria
$contractIds = $this->contractRepository->findAllActiveContractIds();
$batches = array_chunk($contractIds, 100);  // 100 contratos por batch

foreach ($batches as $batch) {
    foreach ($batch as $contractId) {
        $this->processContractInvoice($contractId, $billingPeriod, $stats);
    }
}
```

**Beneficio:** Con 100,000 contratos, procesa en 1,000 iteraciones sin cargar todo en memoria.

---

### 2. **Prevención de Duplicados (Idempotencia)**
```php
// Antes de crear, verifica que NO exista
if ($this->invoiceRepository->existsForPeriod($contractId, $billingPeriod)) {
    $stats['skipped']++;
    return;
}

// Solo crea si no existe
$invoice = $this->invoiceService->createInvoice($contractId, $billingPeriod);
```

**Beneficio:** Si el comando se ejecuta 2 veces, la segunda vez solo crea los faltantes.

---

### 3. **Manejo de Errores Por Contrato**
```php
try {
    // Intenta crear factura
    $invoice = $this->invoiceService->createInvoice(...);
    $stats['success']++;
    
} catch (ContractNotFoundException $e) {
    // Contrato no existe → log y continúa
    $stats['failed']++;
    $stats['failed_contracts'][] = [...];
    
} catch (TariffCalculationException $e) {
    // Error en tarifa → log y continúa
    $stats['failed']++;
    
} catch (ExternalApiException $e) {
    // API externa caída → log y continúa
    $stats['failed']++;
}
```

**Beneficio:** Un contrato fallido NO detiene los demás.

---

### 4. **Estadísticas e Informes**
```php
$stats = [
    'total' => 10000,      // Total procesados
    'success' => 9850,     // Éxito
    'skipped' => 100,      // Duplicados
    'failed' => 50,        // Errores
    'failed_contracts' => [...]
];

// Success rate = 9850 / 10000 = 98.5%
```

---

## 🚀 Cómo Ejecutar

### Manual (por CLI)
```bash
php bin/console invoices:generate-monthly
```

### Automatizado (Cron)
```bash
# /etc/cron.d/factorenergy
0 3 * * * www-data cd /var/www/factorenergy && php bin/console invoices:generate-monthly
```

Ejecuta cada día a las 03:00 UTC.

---

## 🧪 Escalabilidad

### ¿Qué pasa con 100,000 contratos?

**Problema:** Procesar 100,000 contratos toma mucho tiempo.

**Soluciones:**

#### 1. **Índices en Base de Datos**
```sql
-- Acelera búsqueda de contratos activos
CREATE INDEX idx_contracts_status ON contracts(status, id);

-- Acelera búsqueda de lecturas por contrato
CREATE INDEX idx_meter_readings_contract ON meter_readings(contract_id, reading_date);
```

#### 2. **Parallelización**
```php
// Dividir en sub-procesos (future enhancement)
// Procesar N contratos en paralelo
php bin/console invoices:generate-monthly --workers=4
```

#### 3. **Asíncrono con Message Queue**
```php
// En lugar de generar ahora:
// 1. Cola los contratos (10,000 mensajes)
// 2. N workers procesan en paralelo
// 3. Base de datos recibe cargas más distribuidas
```

---

## 📊 Validación: ¿Por qué de noche a las 03:00?

### ✅ Beneficios
- **Bajo tráfico:** Menos clientes usando la plataforma
- **Menos contención BD:** Otras queries no compiten
- **Tiempo suficiente:** 6 horas es mucho para 10,000 contratos
- **Alertas por email:** Los admins ven el reporte al llegar (09:00)

### ❌ Si fuera en horas de negocio (09:00)
- ❌ Bloquea queries de clientes
- ❌ Puede causar timeouts en aplicación principal
- ❌ Afecta la experiencia del usuario
- ❌ Genera reportes de error durante el día de trabajo

---

## 📝 Código: Respuestas a Ejercicio 4

### 4.1a - Trigger
**Respuesta:** Symfony Console Command con Cron.
```bash
php bin/console invoices:generate-monthly
# Ejecutado vía cron cada día a las 03:00
```

### 4.1b - Flujo
```
1. Command comienza
2. Llama a BatchInvoiceGenerator
3. Carga IDs de contratos activos (paginated)
4. Divide en chunks de 100
5. Para cada chunk:
   - Verifica que no exista factura
   - Crea factura con InvoiceService
   - Captura excepciones por contrato
   - Actualiza estadísticas
6. Recolecta: total, success, failed, skipped
7. Envía email de resumen
8. Retorna código de salida
```

### 4.1c - Error Handling
```php
// No escapa, cada error es atrapado:
} catch (ContractNotFoundException $e) { 
    $stats['failed']++; 
} catch (TariffCalculationException $e) { 
    $stats['failed']++; 
} catch (ExternalApiException $e) { 
    $stats['failed']++; 
}
// Continúa con siguiente contrato
```

### 4.1d - Prevención Duplicados
```php
if ($this->invoiceRepository->existsForPeriod($contractId, $billingPeriod)) {
    $stats['skipped']++;
    return;
}
```

### 4.2a - Escalabilidad a 100,000
**Respuesta:** 
- Índices en BD
- Batch size = 100
- Potencialmente: parallelización con workers o message queue

### 4.2b - Timeout en contrato #5,000
**Respuesta:**
- Investigar índices (son vitales)
- Revisar slow query log
- Posible: aumentar memoria PHP o timeout
- Potencial: connection pooling

### 4.2c - ¿Ejecutar en horas de negocio?
**Respuesta:**
- ❌ Compite con tráfico de usuarios
- ❌ Bloquea resources
- ❌ Degrada experiencia
- ✅ Mantener a las 03:00

---

## 💡 Decisiones de Diseño

| Aspecto | Decisión | Por Qué |
|---------|----------|--------|
| **Batch size** | 100 contratos | Balance: rápido + memory efficient |
| **Timing** | 03:00 UTC | Bajo tráfico, tiempo suficiente |
| **Error handling** | Por contrato | No pierde otros |
| **Duplicados** | Verificación en DB | Garantiza idempotencia |
| **Logger** | PSR-3 Logging | Auditoría + debugging |
| **Email** | HTML con tabla | Resumen ejecutivo legible |

---

