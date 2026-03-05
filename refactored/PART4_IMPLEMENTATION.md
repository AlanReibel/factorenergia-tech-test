# Part 4: Batch Invoice Generation Implementation

## Overview
This documentation covers the implementation of **GenerateInvoicesCommand**, a Symfony Console Command that:
- Runs nightly at 03:00 via cron
- Generates invoices for ~10,000 active contracts for the previous month
- Prevents duplicate invoices
- Logs success and failures
- Continues processing on errors
- Sends a summary email to administrators

---

## Architecture Overview

### High-Level Component Diagram

```
┌─────────────────────────────────────────────────────────┐
│           Scheduler (Cron @ 03:00)                      │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│      GenerateInvoicesCommand (Symfony Command)          │
│  - Entry point for batch processing                     │
│  - Orchestrates the entire flow                         │
│  - Handles console output and timing                    │
└────────────────┬────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────┐
│      BatchInvoiceGenerator Service                       │
│  - Loads active contracts (paginated)                   │
│  - Iterates through contracts in batches                │
│  - Calls InvoiceService per contract                    │
│  - Handles individual contract errors gracefully        │
│  - Collects processing statistics                       │
└────────────────┬────────────────────────────────────────┘
                 │
        ┌────────┴────────┐
        ▼                 ▼
   ┌─────────┐      ┌──────────────┐
   │ContractRepo   InvoiceRepository│
   └─────────┘      └──────────────┘
        │                 │
        │                 ▼
        │      existsForPeriod()
        │      (Duplicate Check)
        │
        ▼
   ┌─────────────────────────────────────────────────────┐
   │        InvoiceService                               │
   │  - Creates invoice from contract data               │
   │  - Calculates tariffs                               │
   │  - Computes taxes                                   │
   │  - Persists to database                             │
   └─────────────────────────────────────────────────────┘
        │
        └─────────────────────────┐
                                  │
                                  ▼
                     ┌─────────────────────────┐
                     │  SummaryEmailer Service  │
                     │  - Formats report HTML  │
                     │  - Sends to admins      │
                     │  - Sends errors on fail │
                     └─────────────────────────┘
```

---

## Data Flow & Pseudo-code

### 1. **GenerateInvoicesCommand - Main Entry Point**

```
COMMAND execute():
    IO.display("Starting batch invoice generation")
    
    previousMonth = getPreviousMonth()  // YYYY-MM format
    startTime = now()
    
    TRY
        stats = batchGenerator.generateInvoicesForMonth(previousMonth)
        
        duration = now() - startTime
        
        displayResults(stats, duration)
        
        emailer.sendSummaryEmail(stats, previousMonth)
        IO.success("Process completed")
        
        LOG.info("Batch completed", stats)
        RETURN SUCCESS
        
    CATCH exception
        LOG.critical("Batch failed", exception)
        emailer.sendErrorNotification(exception)
        IO.error(exception.message)
        RETURN FAILURE
```

### 2. **BatchInvoiceGenerator - Core Batch Processing**

```
SERVICE generateInvoicesForMonth(billingPeriod):
    LOGGER.info("Starting batch generation")
    
    stats = {
        total: 0,
        success: 0,
        skipped: 0,
        failed: 0,
        failed_contracts: []
    }
    
    // Load all active contract IDs (memory efficient)
    contractIds = contractRepository.findAllActiveContractIds()
    stats.total = count(contractIds)
    
    IO.text("Found " + stats.total + " active contracts")
    
    // Process in batches of 100 to control memory
    batches = chunk(contractIds, 100)
    processedCount = 0
    
    FOR EACH batch IN batches
        FOR EACH contractId IN batch
            processContractInvoice(contractId, billingPeriod, stats)
            
            processedCount++
            
            // Display progress every 500 contracts
            IF processedCount % 500 == 0
                IO.text("Processed " + processedCount + " / " + stats.total)
            END IF
        END FOR
    END FOR
    
    LOGGER.info("Batch completed", stats)
    RETURN stats
```

### 3. **ProcessContractInvoice - Error Handling Per Item**

```
PRIVATE processContractInvoice(contractId, billingPeriod, stats):
    TRY
        // DUPLICATE CHECK - PREVENT RE-GENERATION
        IF invoiceRepository.existsForPeriod(contractId, billingPeriod)
            LOGGER.debug("Invoice exists", {contractId, billingPeriod})
            stats.skipped++
            RETURN  // Skip duplicates
        END IF
        
        // GENERATE INVOICE
        invoice = invoiceService.createInvoice(contractId, billingPeriod)
        
        // SUCCESS
        LOGGER.info("Invoice generated", {
            contract_id: contractId,
            invoice_id: invoice.id,
            amount: invoice.totalAmount,
            kwh: invoice.totalKwh
        })
        stats.success++
        
    CATCH ContractNotFoundException
        // Contract doesn't exist - skip gracefully
        LOGGER.warning("Contract not found", {contractId})
        stats.failed++
        stats.failed_contracts.add({contractId, "Contract not found"})
        
    CATCH TariffCalculationException
        // Tariff error - log and skip
        LOGGER.error("Tariff calculation failed", {contractId, error})
        stats.failed++
        stats.failed_contracts.add({contractId, "Tariff calculation failed"})
        
    CATCH ExternalApiException
        // External API error - log and skip
        LOGGER.error("External API error", {contractId, error})
        stats.failed++
        stats.failed_contracts.add({contractId, "External API error"})
        
    CATCH UnexpectedException
        // Any other error - log and continue
        LOGGER.error("Unexpected error", {contractId, error, trace})
        stats.failed++
        stats.failed_contracts.add({contractId, "Unexpected error"})
    END TRY
    
    // CRITICAL: Process continues regardless of error
    // This ensures all contracts are processed
```

### 4. **SummaryEmailer - Notification**

```
SERVICE sendSummaryEmail(stats, billingPeriod):
    subject = "[FactorEnergia] Invoices Generated - " + billingPeriod 
              + " (Success: " + successRate + "%)"
    
    body = buildHtmlReport(stats)
    
    email.to = administratorEmails
    email.subject = subject
    email.body = body
    email.from = noreply@factorenergy.es
    
    mailer.send(email)
    
    LOGGER.info("Summary email sent", {billingPeriod, stats})
```

---

## Key Design Decisions & Rationale

### 1. **Batch Processing with Pagination**
**Decision:** Load contracts in batches of 100, process per-contract

**Why:**
- **Memory Efficiency:** Prevents loading 10,000 objects into memory simultaneously
- **Database Efficiency:** Reduces connection timeout risks
- **Graceful Degradation:** If one contract fails, others continue
- **Monitoring:** Progress feedback every 500 items for long-running processes

---

### 2. **Idempotency via Duplicate Check**
**Decision:** Query database BEFORE inserting invoice

**Why:**
- **Prevents Duplicates:** `existsForPeriod(contractId, billingPeriod)` ensures one invoice per period
- **Safe to Rerun:** Command can be re-executed if interrupted
- **Database Integrity:** Uses SQL LIMIT 1 for performance (early exit)
- **No Locking Issues:** READ operation before write, no transaction locks needed

```php
// Efficient query - stops at first match
SELECT 1 FROM invoices 
WHERE contract_id = ? AND billing_period = ? LIMIT 1
```

---

### 3. **Error Containment**
**Decision:** Try/catch around each contract, continue on error

**Why:**
- **Batch Continuity:** 1 failing contract ≠ 10,000 contracts skipped
- **Specific Error Logging:** Different handlers for different exception types
- **Data Collection:** Tracks which contracts failed and why
- **Operational Transparency:** All failures documented in email report

---

### 4. **Comprehensive Logging**
**Decision:** Log at DEBUG, INFO, WARNING, ERROR, CRITICAL levels

**Why:**
- **Debug:** Skipped invoices (duplicates)
- **Info:** Successful invoice creation + batch completion
- **Warning:** Contract not found (data inconsistency)
- **Error:** Exceptions (tariff, API, unexpected)
- **Critical:** Batch process failure

---

### 5. **Cron Scheduling at 03:00**
**Decision:** Off-business-hours scheduled job

**Why:**
- **Low Load:** 03:00 is typically off-peak
- **Morning Review:** Admins see results in summary email at 06:00
- **No User Impact:** Background processing doesn't affect user experience
- **Database Backup Friendly:** Typically after nightly backups

**Cron Entry:**
```bash
0 3 * * * /usr/bin/php /app/bin/console invoices:generate-monthly
```

---

### 6. **Statistics Collection**
**Decision:** Collect `total`, `success`, `skipped`, `failed`, `failed_contracts[]`

**Why:**
- **Performance Metrics:** Success rate, processing time per contract
- **Operational Visibility:** Know what worked and what didn't
- **Debugging:** Detailed failure reasons for each contract
- **Audit Trail:** Email report forms compliance record

---

### 7. **HTML Email Formatting**
**Decision:** Structured HTML with styling, not plain text

**Why:**
- **Readability:** Color-coded results (green=success, red=failure)
- **Professionalism:** Branded email with logo space
- **Mobile Friendly:** Responsive tables
- **Parsing:** Can be parsed by monitoring systems

---

## Database Schema Requirements

The implementation assumes this table structure:

```sql
-- Contracts table with active flag
CREATE TABLE contracts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tariff_id INT NOT NULL,
    country VARCHAR(2) NOT NULL,
    nif VARCHAR(20) UNIQUE,
    cups VARCHAR(20),
    street_address VARCHAR(255),
    city VARCHAR(100),
    postal_code VARCHAR(10),
    start_date DATETIME NOT NULL,
    estimated_annual_kwh DECIMAL(10,2),
    is_active TINYINT(1) DEFAULT 1,  -- <-- Required for filtering
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tariff_id) REFERENCES tariffs(id)
);

-- Invoices table
CREATE TABLE invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    contract_id INT NOT NULL,
    billing_period VARCHAR(7) NOT NULL,  -- YYYY-MM format
    total_kwh DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'draft',  -- draft, sent, paid, cancelled
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES contracts(id),
    UNIQUE KEY unique_invoice_per_period (contract_id, billing_period)
);
```

**Critical Indexes:**
```sql
-- For efficient duplicate check
CREATE INDEX idx_invoices_contract_period 
ON invoices(contract_id, billing_period);

-- For batch contract loading
CREATE INDEX idx_contracts_active 
ON contracts(is_active, id);
```

---

## Cron Configuration Example

### Setup on Linux/Unix:

```bash
# Edit crontab
crontab -e

# Add entry (run at 03:00 AM daily)
0 3 * * * /usr/bin/php /app/bin/console invoices:generate-monthly >> /var/log/invoices.log 2>&1
```

### Environment Setup:
```bash
# Install Symfony CLI
curl https://get.symfony.com/cli/installer | bash

# Make command executable via Symfony console
php bin/console invoices:generate-monthly
```

---

## Symfony Configuration (services.yaml)

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

## Performance Characteristics

| Metric | Value | Notes |
|--------|-------|-------|
| **Contracts Processed** | 10,000 | Typical nightly batch |
| **Batch Size** | 100 | Memory management |
| **Expected Duration** | 15-30 min | ~90-180ms per contract |
| **Memory Peak** | <512MB | Only 100 contracts in memory at once |
| **Database Connections** | 1 | Reused throughout |
| **Log Entries** | ~10,020 | 1 per contract + summaries |

---

## Testing Strategy

### Unit Tests:
```php
// Test BatchInvoiceGenerator
testGenerateInvoicesForMonth()
testProcessContractInvoice_Success()
testProcessContractInvoice_DuplicateSkipped()
testProcessContractInvoice_ErrorHandling()
testStatisticsCollection()
```

### Integration Tests:
```php
// Full flow with database
testCommandExecution()
testDuplicateInvoicesPrevention()
testEmailGeneration()
testErrorEmailOnFailure()
```

### Load Tests:
```php
// Simulate 10,000 contracts
testPerformanceWith10kContracts()
testMemoryUsageStaysConstant()
```

---

## Monitoring & Alerting

### Key Metrics to Monitor:
1. **Success Rate** - Should be >99% after fixing issues
2. **Processing Time** - Alert if >45 minutes
3. **Failed Contracts** - Investigate >10 failures
4. **Email Delivery** - Confirm summary email sent

### Alerts to Configure:
```
- IF success_rate < 95% THEN page on-call
- IF processing_time > 45min THEN warn
- IF failed_contracts > 100 THEN alert
- IF email_failure THEN critical alert
```

---

---

# Answers to Scaling Questions

## EXERCISE 4.2: Scaling Scenarios

### a) **If contracts grow to 100,000 and nightly process takes too long:**

**Recommendation:** Implement **date-based partitioning** instead of processing all at once:

1. **Partition by Contract ID:** Run parallel commands on different contract ranges:
   ```bash
   # Run 4 jobs in parallel, each processing 25,000 contracts
   php bin/console invoices:generate-monthly --from-id=0 --to-id=25000 &
   php bin/console invoices:generate-monthly --from-id=25001 --to-id=50000 &
   php bin/console invoices:generate-monthly --from-id=50001 --to-id=75000 &
   php bin/console invoices:generate-monthly --from-id=75001 --to-id=100000 &
   ```

2. **Use Message Queue (Async):** Push contracts to worker queue:
   - Symfony Messenger: Dispatch ContractInvoiceGenerationMessage for each contract
   - Workers pick up jobs in parallel from RabbitMQ/Redis

3. **Database Optimization:**
   - Add covering index: `(contract_id, billing_period, status)`
   - Batch inserts with multi-row INSERT
   - Use `INSERT IGNORE` to handle duplicates at DB level

**Expected Improvement:** 4x faster with 4 parallel workers, or 8-10x with message queue workers.

---

### b) **Process fails at contract #5,000 due to database timeout:**

**Investigation Steps & Fixes:**

1. **Root Cause Analysis:**
   ```sql
   -- Check slow queries
   SELECT * FROM mysql.slow_log WHERE query_time > 1;
   
   -- Check lock waits
   SHOW PROCESSLIST;
   SHOW ENGINE INNODB STATUS;
   ```

2. **Likely Culprits:**
   - **Missing index** on `(contract_id, billing_period)` for duplicate check
   - **Long transaction:** Single transaction holding 5,000 rows
   - **Table lock:** Other process modifying contracts table

3. **Fixes to Implement:**
   ```php
   // Add explicit timeout
   $this->pdo->setAttribute(PDO::ATTR_TIMEOUT, 30);
   
   // Commit after each batch (prevent long transactions)
   foreach ($batches as $batch) {
       $this->db->beginTransaction();
       // Process batch
       $this->db->commit();  // <-- Release locks
   }
   
   // Ensure indexes exist
   ALTER TABLE invoices ADD INDEX idx_contract_billing 
   (contract_id, billing_period);
   ```

4. **Additional Safeguards:**
   - Add retry logic with exponential backoff
   - Split into smaller batches (50 instead of 100)
   - Run during lower-traffic period (02:00 instead of 03:00)

---

### c) **Colleague suggests running during business hours (09:00):**

**Concerns & Objections:**

1. **Database Contention:** Invoicing batch locks tables during peak user activity → timeout risk increases
2. **API Throttling:** Market rate APIs might be overloaded during business hours → calculation failures spike
3. **User Experience Impact:** Queries slow down for customers viewing invoices/accounts simultaneously
4. **Email Overload:** Admins and customers receive emails during busy time → email queue backs up
5. **Debugging Complexity:** If something breaks at 09:00, on-call engineer is context-switching with production incidents

**Recommended Compromise:**
- **Keep 03:00 default** for stability
- **Offer optional business-hours run** for missed batches
- **Monitor resource usage** at 03:00 — if low, no real impact anyway
- **Use read replicas** if scaling: Invoice generation reads from replica, doesn't contend with user queries

---

## Summary Table: Optimization Techniques

| Issue | Solution | Impact |
|-------|----------|--------|
| **Long duration (100k)** | Parallel processing + message queue | 8-10x faster |
| **Database timeouts** | Indexes + batched commits + retry logic | Eliminates 90% of timeouts |
| **High memory** | Chunked processing (already done) | Maintains <512MB |
| **Email bottleneck** | Async email queue | Reduces report generation time |
| **Duplicate checking slow** | Unique constraint + INSERT IGNORE | DB handles duplicates |
| **Business-hours safety** | Use read replicas | No impact on users |

---

## Deployment Checklist

- [ ] Add `is_active` column to contracts table
- [ ] Create indexes on `(contract_id, billing_period)` in invoices
- [ ] Deploy GenerateInvoicesCommand to production
- [ ] Deploy BatchInvoiceGenerator service
- [ ] Deploy SummaryEmailer service
- [ ] Configure mailer in `.env`:
  ```
  ADMIN_EMAILS="admin1@company.es,admin2@company.es"
  MAILER_FROM="invoices@factorenergy.es"
  ```
- [ ] Add cron job: `0 3 * * * php /app/bin/console invoices:generate-monthly`
- [ ] Create log rotation for `/var/log/invoices.log`
- [ ] Set up monitoring alerts for success rate
- [ ] Test dry-run in staging environment
- [ ] Document on-call procedures for failures

---

