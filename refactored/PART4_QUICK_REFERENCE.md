# Part 4: Quick Implementation Reference

## Files Created

### 1. **GenerateInvoicesCommand**
- **Path:** `refactored/Command/GenerateInvoicesCommand.php`
- **Purpose:** Symfony Console Command entry point
- **Run:** `php bin/console invoices:generate-monthly`
- **Cron:** `0 3 * * * php /app/bin/console invoices:generate-monthly`

### 2. **BatchInvoiceGenerator Service**
- **Path:** `refactored/Service/BatchInvoiceGenerator.php`
- **Purpose:** Core batch processing logic
- **Features:**
  - Loads contracts in batches (100 per batch)
  - Detects and skips duplicates
  - Handles errors gracefully (continues on failure)
  - Collects statistics (success/failure/skipped)

### 3. **SummaryEmailer Service**
- **Path:** `refactored/Service/SummaryEmailer.php`
- **Purpose:** Send HTML-formatted summary emails
- **Features:**
  - Summary email with results
  - Error notification emails
  - Professional HTML formatting

### 4. **Extended ContractRepository**
- **Method:** `findAllActiveContractIds()`
- **Purpose:** Return contract IDs for batch processing
- **Returns:** Array of integers (memory efficient)

### 5. **Extended InvoiceRepository**
- **Method:** `existsForPeriod($contractId, $billingPeriod)`
- **Purpose:** Check for duplicate invoices
- **Returns:** Boolean

### 6. **Documentation**
- **Path:** `refactored/PART4_IMPLEMENTATION.md`
- **Contains:**
  - Architecture diagram
  - Pseudo-code for all components
  - Design decision explanations
  - Database schema requirements
  - Cron configuration
  - Performance characteristics
  - Testing strategy
  - Scaling question answers

---

## Architecture Summary

```
Cron (03:00) 
    ↓
GenerateInvoicesCommand
    ↓
BatchInvoiceGenerator
    ├─→ Load contracts (10k in batches of 100)
    ├─→ For each contract:
    │   ├─→ Check for existing invoice (duplicate prevention)
    │   ├─→ If exists: skip
    │   ├─→ If not: call InvoiceService.createInvoice()
    │   └─→ Handle errors gracefully
    └─→ Collect statistics
    ↓
SummaryEmailer
    └─→ Send HTML report to admins
```

---

## Key Features

### 1. **Duplicate Prevention**
```php
// In BatchInvoiceGenerator.processContractInvoice()
if ($this->invoiceRepository->existsForPeriod($contractId, $billingPeriod)) {
    $stats['skipped']++;
    return; // Safe to skip
}
```

### 2. **Error Handling**
```php
// Catches specific exceptions and continues
- ContractNotFoundException → Log + skip
- TariffCalculationException → Log + skip
- ExternalApiException → Log + skip
- Generic Exception → Log + skip

// Batch continues regardless
```

### 3. **Performance Management**
```php
// Process in batches of 100
$batches = array_chunk($contractIds, 100);

// Display progress
if ($processedCount % 500 === 0) {
    $io->text("Processed $processedCount / $total");
}
```

### 4. **Comprehensive Statistics**
```php
$stats = [
    'total' => 10000,           // Total contracts processed
    'success' => 9995,          // Successfully generated
    'skipped' => 3,             // Already existed (duplicates)
    'failed' => 2,              // Failed to generate
    'failed_contracts' => [...]  // Details of failures
];
```

---

## Running the Command

### Manual Test:
```bash
php bin/console invoices:generate-monthly
```

**Output:**
```
 ! [NOTE] Running this command

 Monthly Invoice Generation Process 
 =====================================

 Starting batch invoice generation for previous month...
 Processing billing period: 2026-02
 Found 10000 active contracts to process
 Processing... 500/10000 (2026-03-05 03:15:42)
 Processing... 1000/10000 (2026-03-05 03:18:20)
 ...

 Batch Processing Results 
 ═══════════════════════════════════════════════════════

 ┌──────────────────────────────────────┬────────────┐
 │ Metric                               │ Value      │
 ├──────────────────────────────────────┼────────────┤
 │ Total Contracts Processed            │ 10000      │
 │ Successfully Generated               │ 9995       │
 │ Skipped (Duplicate)                  │ 3          │
 │ Failed                               │ 2          │
 │ Success Rate                         │ 99.95%     │
 │ Processing Time                      │ 24.35 sec  │
 │ Average per Contract                 │ 0.002 sec  │
 └──────────────────────────────────────┴────────────┘

 [OK] Summary email sent to administrators
```

### Cron Setup (Linux):
```bash
# Edit crontab
crontab -e

# Add this line (runs at 03:00 every day)
0 3 * * * /usr/bin/php /app/bin/console invoices:generate-monthly >> /var/log/invoices.log 2>&1

# Verify cron entry
crontab -l
```

---

## Database Requirements

### New Columns Needed:
```sql
-- Add if not present
ALTER TABLE contracts ADD COLUMN is_active TINYINT(1) DEFAULT 1;

-- Create indexes for performance
CREATE INDEX idx_contracts_active ON contracts(is_active, id);
CREATE INDEX idx_invoices_contract_period ON invoices(contract_id, billing_period);

-- Ensure unique constraint (prevents duplicates at DB level)
ALTER TABLE invoices ADD UNIQUE KEY unique_invoice_per_period 
    (contract_id, billing_period);
```

---

## Monitoring & Logs

### Log Levels:
```
DEBUG   → Skipped invoices (duplicates)
INFO    → Invoice created successfully, batch completion
WARNING → Contract not found, data inconsistency
ERROR   → Tariff, API, unexpected errors
CRITICAL → Batch process failure
```

### Expected Log Output:
```
[2026-03-05 03:00:01] app.INFO: Starting batch invoice generation 
    {"billing_period":"2026-02","batch_size":100}
[2026-03-05 03:00:02] app.DEBUG: Invoice already exists for contract 
    {"contract_id":1234,"billing_period":"2026-02"}
[2026-03-05 03:00:03] app.INFO: Invoice generated successfully 
    {"contract_id":5678,"invoice_id":100001,"amount":145.50,"kwh":350.75}
[2026-03-05 03:25:10] app.INFO: Successfully completed batch processing 
    {"total":10000,"success":9995,"skipped":3,"failed":2}
```

---

## Email Report Example

### Email Subject:
```
[FactorEnergia] Invoices Generated - 2026-02 (Success Rate: 99.95%)
```

### Email Content (HTML):
```
┌─────────────────────────────────────┐
│ Invoice Generation Summary Report    │
│ Billing Period: 2026-02              │
├─────────────────────────────────────┤
│ Total Contracts Processed: 10,000    │
│ ✓ Successfully Generated: 9,995      │
│ ⊘ Skipped (Duplicates): 3            │
│ ✗ Failed: 2                          │
│ Success Rate: 99.95%                 │
├─────────────────────────────────────┤
│ Failed Contracts:                   │
│ - Contract #12345: API timeout      │
│ - Contract #67890: Tariff not found │
└─────────────────────────────────────┘
```

---

## Error Handling Flow

```
For Each Contract:
    ├─ Check if exists → YES
    │   └─ Skip (stats['skipped']++)
    │
    └─ Check if exists → NO
       ├─ TRY generate invoice
       │  ├─ SUCCESS → stats['success']++
       │  │
       │  └─ EXCEPTION
       │     ├─ ContractNotFoundException → stats['failed']++
       │     ├─ TariffCalculationException → stats['failed']++
       │     ├─ ExternalApiException → stats['failed']++
       │     └─ Other Exception → stats['failed']++
       │
       └─ CONTINUE TO NEXT CONTRACT (no interruption)
```

---

## Performance Expectations

| Scenario | Time | Notes |
|----------|------|-------|
| 10,000 contracts | 20-30 min | ~120-180ms per contract |
| 100,000 contracts | 3-4 hours | Single threaded |
| Parallel (4x) | 45-60 min | With 4 worker processes |

### Memory Usage:
- **Fixed:** ~50MB for application
- **Variable:** ~100KB per batch (100 contracts)
- **Peak:** ~150MB total
- **Stable:** Constant throughout (no memory leak)

---

## Scaling Solutions (from Part 4.2)

| Problem | Solution | Expected Improvement |
|---------|----------|----------------------|
| Takes 4+ hours for 100k | Parallel workers (4-8) | 4-8x faster |
| Database timeout at 5k | Add indexes + retry logic | 90% timeout elimination |
| Peak hours lock contention | Run at 02:00 or use read replicas | Zero user impact |

---

## Configuration (services.yaml)

```yaml
services:
    App\Command\GenerateInvoicesCommand:
        tags: ['console.command']

    App\Service\BatchInvoiceGenerator:
        arguments:
            - '@App\Repository\ContractRepository'
            - '@App\Repository\InvoiceRepository'
            - '@App\Service\InvoiceService'
            - '@logger'

    App\Service\SummaryEmailer:
        arguments:
            - '@logger'
            - '%env(ADMIN_EMAILS)%'
            - '%env(MAILER_FROM)%'
            - 'FactorEnergia'
```

---

## Environment Variables (.env)

```bash
# Email configuration
ADMIN_EMAILS="admin1@company.es,admin2@company.es"
MAILER_FROM="invoices@factorenergy.es"
MAILER_DSN="smtp://smtp.gmail.com:587"

# Optional: Slack notifications
SLACK_WEBHOOK_URL="https://hooks.slack.com/services/..."
```

---

## Troubleshooting

| Issue | Cause | Solution |
|-------|-------|----------|
| Too many duplicates | `existsForPeriod()` not working | Check invoice table indexes |
| Email not sent | Service not configured | Check MAILER_DSN in .env |
| Timeout at 5k | Missing database index | Run `ALTER TABLE invoices ADD INDEX...` |
| High memory usage | Batch size too large | Reduce `BATCH_SIZE` from 100 to 50 |
| Command not found | Not registered as service | Add to services.yaml with `console.command` tag |

---

## Next Steps (Post-Deployment)

1. **Monitor first run** - Watch logs in real-time
2. **Validate invoice counts** - Compare generated vs total contracts
3. **Check email delivery** - Confirm summary email arrives
4. **Set up alerts:**
   - Alert if success rate < 95%
   - Alert if processing time > 45 minutes
   - Alert if email delivery fails
5. **Document failure procedures** - What to do if batch fails
6. **Schedule retry window** - When to manually re-run if needed

---
