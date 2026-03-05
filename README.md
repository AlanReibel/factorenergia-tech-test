# 🔌 FactorEnergia - Technical Assessment

Solución completa para la prueba técnica de FactorEnergia utilizando **Symfony** como framework principal.

---

## 📚 Estructura del Proyecto

Este proyecto se divide en **4 ejercicios progresivos**, cada uno con su propia documentación:

### **Part 1: Consultas SQL** 📊
- **Carpeta:** `part1-sql/`
- **Objetivo:** Diseñar esquema de base datos y consultas SQL para gestión de contratos y tarifas
- **Contenido:**
  - `01_queries.sql` - Todas las consultas SQL requeridas
  - `schema.sql` - Esquema de base de datos
  - `README.md` - Documentación detallada

📖 [Ver documentación de Part 1](part1-sql/README.md)

---

### **Part 2: Code Review & Refactoring** 🔍
- **Carpeta:** `part2-php/`
- **Objetivo:** Analizar código vulnerable, identificar 11 issues de seguridad y refactorizar con patrones SOLID
- **Contenido:**
  - ✅ Análisis de vulnerabilidades de seguridad
  - ✅ Explicación de cada problema y solución
  - ✅ Código refactorizado completo
  - ✅ Estrategia de testing
  - ✅ Patrones de diseño (Strategy, Dependency Injection)

📖 [Ver documentación de Part 2](part2-php/README.md)

---

### **Part 3: API Integration** 🌐
- **Carpeta:** `part3-api/`
- **Objetivo:** Crear módulo de integración con la API de regulador portugués (ERSE)
- **Contenido:**
  - 📄 Sincronización de contratos con API externa
  - 📄 Manejo de errores y validaciones
  - 📄 Auditoría de intentos de sincronización
  - 📄 Autenticación y transformación de datos

📖 [Ver documentación de Part 3](part3-api/README.md)

---

### **Part 4: Batch Processing & Scaling** ⚙️
- **Carpeta:** `part4-batch/`
- **Objetivo:** Implementar generación nocturna de facturas para todos los contratos (~10,000)
- **Contenido:**
  - 🏗️ Symfony Console Command para ejecución programada
  - 🏗️ Batch processing con chunks de 100 contratos
  - 🏗️ Manejo robusto de errores (no detiene batch)
  - 🏗️ Prevención de duplicados (idempotencia)
  - 🏗️ Notificaciones por email con resumen
  - 🏗️ Logging completo para auditoría

📖 [Ver documentación de Part 4](part4-batch/README.md)

---

## 📚 Índice de Documentación

Para una navegación completa por toda la documentación del proyecto, consulta **[DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md)** que contiene:
- Búsqueda por tema
- Flujos de lectura recomendados
- Referencias cruzadas
- Estructura completa

---

## 🎯 Flujo de Lectura Recomendado

### **Lectura Rápida (15 minutos)**
1. Este README (estás aquí)
2. Resumen ejecutivo de cada parte

### **Comprensión Profunda (2-3 horas)**
1. **Part 1** - Esquema y queries SQL
2. **Part 2** - Análisis de vulnerabilidades y refactoring
3. **Part 3** - Integración con API externa
4. **Part 4** - Arquitectura y patrones avanzados

### **Implementación (4-5 horas)**
Revisar paso a paso cada carpeta y su README.md para implementación práctica

---

## 📁 Estructura General

```
FE-PruebaTecnica/
├── README.md                    ← TÚ ESTÁS AQUÍ
├── PruebaTecnica.pdf           (Enunciado original)
├── erse_api.json               (Especificación API externa)
│
├── part1-sql/                  (Part 1: SQL)
│   ├── README.md              
│   ├── 01_queries.sql
│   └── schema.sql
│
├── part2-php/                  (Part 2: Code Review & Refactoring)
│   ├── README.md
│   ├── ANALYSIS.md            (Análisis completo)
│   ├── SOLUTIONS.md           (Soluciones detalladas)
│   ├── Controller/
│   ├── Entity/
│   ├── Repository/
│   ├── Service/
│   ├── Exception/
│   ├── Tests/
│   └── COMPARISON.md          (Antes vs Después)
│
├── part3-api/                  (Part 3: API Integration)
│   ├── README.md
│   ├── Controller/
│   ├── Entity/
│   ├── Repository/
│   └── Service/
│
├── refactored/                (Part 4: Batch & Design Patterns)
│   ├── README.md
│   ├── Command/
│   ├── Controller/
│   ├── Entity/
│   ├── Repository/
│   ├── Service/
│   ├── Exception/
│   └── Tests/
│
└── docs/                       (Documentación adicional)
```

---

## 🔧 Tecnologías Utilizadas

- **Framework:** Symfony
- **Lenguaje:** PHP 8.0+
- **Base de Datos:** SQL Server / MySQL
- **Patrones:** SOLID, Strategy, Dependency Injection, Repository, Factory
- **Testing:** PHPUnit

---

## ✨ Características Principales

| Aspecto | Descripción |
|---------|-------------|
| **Seguridad** | ✅ Protección contra SQL Injection, Input Validation, Output Encoding |
| **Testing** | ✅ Unit tests y ejemplos de integración |
| **Mantenibilidad** | ✅ SOLID principles, código clean y documentado |
| **Performance** | ✅ Batch processing optimizado, lazy loading de relaciones |
| **Extensibilidad** | ✅ Fácil agregar nuevas tarifas, validadores y transportadores |
| **Error Handling** | ✅ Excepciones custom, logging detallado |

---

## 📞 Notas de Implementación

Cada carpeta tiene su propio **README.md** con:
- ✅ Resumen del ejercicio
- ✅ Decisiones de diseño explicadas
- ✅ Cómo usar el código
- ✅ Patrones aplicados
- ✅ Testing y validación

**Comienza por las carpetas en orden:** Part 1 → Part 2 → Part 3 → Part 4

---

*Última actualización: 5 de marzo de 2026*
