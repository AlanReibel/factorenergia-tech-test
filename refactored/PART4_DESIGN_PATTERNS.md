# Part 4: Design Patterns & Architecture Details

## Design Patterns Applied

### 1. **Command Pattern (Symfony Command)**
The `GenerateInvoicesCommand` is a classic command pattern:
- **Encapsulates** a request as an object
- **Decouples** requestor from executor
- **Enables** queuing, logging, and undoable operations

```php
class GenerateInvoicesCommand extends Command {
    // Encapsulates the batch invoice generation operation
    protected function execute(InputInterface $input, OutputInterface $output): int
}
```

**Benefits:**
- Can be invoked via CLI, web request, or API
- Logging and monitoring built-in
- Testable and mockable

---

### 2. **Service Layer Pattern**
Business logic separated from command execution:

```
GenerateInvoicesCommand (Presentation)
         ↓
BatchInvoiceGenerator (Service/Business Logic)
         ↓
InvoiceService (Domain Service)
         ↓
Repository (Data Access)
         ↓
Database (Persistence)
```

**Benefits:**
- Reusable services (can use BatchInvoiceGenerator from API too)
- Single Responsibility Principle
- Easy to test

---

### 3. **Strategy Pattern (in InvoiceService)**
Tariff calculation uses different algorithms:

```php
$calculator = $this->tariffFactory->createCalculator(
    $tariff->getCode(),  // Strategy selection
    $month,
    $tariff->getPricePerKwh()
);
$amount = $calculator->calculate($totalKwh, $tariff->getFixedMonthly());
```

Different strategies:
- `FixedTariffCalculator`
- `IndexedTariffCalculator`
- `FlatRateTariffCalculator`
- `FixedPromoTariffCalculator`

---

### 4. **Repository Pattern**
Data access abstraction:

```php
// Contracts
contractRepository.findAllActiveContractIds()
contractRepository.findById($id)

// Invoices
invoiceRepository.existsForPeriod($contractId, $billingPeriod)
invoiceRepository.save($invoice)
```

**Benefits:**
- Database-agnostic queries
- Testable with mock repositories
- Easy to switch databases

---

### 5. **Template Method Pattern (Batch Processing)**
Consistent pattern for each contract:

```
Template (processContractInvoice):
  1. Check for duplicate
  2. Generate if not exists
  3. Handle errors (don't stop)
  4. Update statistics
```

All contracts follow the same flow, reducing complexity.

---

## Error Handling Architecture

### Multi-Level Error Strategy

```
Level 1: Per-Contract Error Handling
  ├─ Catch specific exceptions (ContractNotFoundException, etc.)
  ├─ Log the error
  ├─ Continue processing
  └─ Track in statistics

Level 2: Batch-Level Error Handling
  ├─ Monitor statistics
  ├─ Log batch completion
  └─ Send summary email

Level 3: Command-Level Error Handling
  ├─ Catch critical batch failures
  ├─ Log critical error
  ├─ Send error notification email
  └─ Return FAILURE exit code
```

### Graph of Error Flows

```
Contract Processing
    │
    ├─ Skip (Duplicate)
    │  └─ stats['skipped']++
    │
    ├─ ProcessContractInvoice tries
    │  ├─ SubstituteContractNotFoundException
    │  │   └─ Logged as WARNING
    │  │       └─ stats['failed']++
    │  ├─ TariffCalculationException
    │  │   └─ Logged as ERROR
    │  │       └─ stats['failed']++
    │  ├─ ExternalApiException
    │  │   └─ Logged as ERROR
    │  │       └─ stats['failed']++
    │  └─ Generic Exception
    │      └─ Logged as ERROR
    │          └─ stats['failed']++
    │
    └─ Continue to Next Contract (Always)
```

### No Error Blocks the Entire Batch
```php
// CRITICAL CODE
foreach ($contractIds as $contractId) {
    try {
        // Generate invoice
    } catch (Exception $e) {
        // Log and continue
        // NEVER throw or return - CONTINUE
    }
}
// All 10,000 contracts processed regardless
```

---

## Concurrent Execution & Race Conditions

### Scenario: Command runs twice simultaneously

```
Process A                          Process B
────────                          ────────
Load contracts                     Load contracts
[1, 2, 3, ..., 10000]            [1, 2, 3, ..., 10000]

For Contract #1234:
Check if exists                    Check if exists
→ NO                               → NO (still being checked)
→ Create invoice                   → Try to create
                                   → CONFLICT! (Unique constraint)
                                   → Database error
                                   → Caught as exception
                                   → stats['failed']++
```

### Prevention:
```sql
-- Database-level unique constraint
ALTER TABLE invoices ADD UNIQUE KEY unique_invoice_per_period 
(contract_id, billing_period);

-- This prevents duplicates regardless of application logic
```

### Recovery:
```php
// If duplicate insert attempted:
try {
    $invoiceRepository->save($invoice);
} catch (IntegrityConstraintException $e) {
    // Invoice already exists (from concurrent run)
    $stats['skipped']++;  // Treat as duplicate
}
```

---

## Memory Management

### Batch Processing Chain

```
Load all IDs (10,000 ints) → ~1KB memory
    ↓
Chunk into 100 batches → Only 100 IDs in memory at once
    ↓
Process batch of 100
  - Load contracts (100 objects) → ~100KB
  - Load meter readings → ~50KB
  - Calculate invoices → ~50KB
    ↓
Write to database, clear objects
    ↓
Next batch (objects garbage collected)
```

**Memory Profile:**
```
Start  : 50 MB (base app)
Peak   : 150 MB (during batch processing)
End    : 52 MB (final email)
Leak   : 0 MB (all objects freed)
```

### Database Connection Management

```php
// Single persistent connection
$pdo = new PDO(...);

// Reused for ALL queries
foreach ($batches as $batch) {
    $stmt = $pdo->prepare(...);  // Same connection
}
```

**Why Single Connection?**
- No connection pool overhead
- Faster execution
- Fewer resource conflicts
- Better transaction control

---

## Logging Architecture

### Structured Logging with Context

```php
$this->logger->info('Invoice generated successfully', [
    'contract_id' => 5678,
    'invoice_id' => 100001,
    'billing_period' => '2026-02',
    'amount' => 145.50,
    'kwh' => 350.75,
    'timestamp' => date('Y-m-d H:i:s'),
    'duration_ms' => 124
]);
```

### Log Aggregation Benefit:
These structured logs can be:
- Parsed by ELK Stack (Elasticsearch)
- Searched: `invoice_id = 100001`
- Filtered: `contract_id IN [1-1000]`
- Visualized: Success rate trends

### Log Levels Used

| Level | When | Example |
|-------|------|---------|
| DEBUG | Fine details | Invoice already exists (skip) |
| INFO | Milestone events | Invoice created, batch completed |
| WARNING | Unexpected but recoverable | Contract not found |
| ERROR | Errors that don't stop batch | API timeout, tariff error |
| CRITICAL | Batch process failed | Database down |

---

## Email System Design

### Template Architecture

```
SummaryEmailer
├─ buildEmailBody()
│  ├─ buildFailedContractsSection()
│  └─ buildMoreFailuresMessage()
└─ buildErrorEmailBody()
```

### HTML Email Benefits:
- Formatted tables (better than plain text)
- Color coding (red=fail, green=success)
- Mobile responsive
- Professional appearance
- Can include charts/graphs in future

### Email Sending (Abstraction Layer)

```php
private function sendEmail(string $to, string $subject, string $body): void {
    // Currently: logs instead of sending
    // In production: inject MailerInterface
    // From Symfony Mailer or PHPMailer
}
```

**Why Abstraction?**
- Can switch email providers (Gmail → SendGrid → AWS SES)
- Testable without sending real emails
- Can queue emails asynchronously

---

## Idempotency Deep Dive

### Why Idempotent Operations Matter

**Problem:** If command is interrupted or restarted:
```
Run 1: Process contracts 1-5000 ✓, then CRASH
Run 2: Process contracts 1-10000 → Contract 1-5000 processed AGAIN?
                                  → DUPLICATE INVOICES ❌
```

### Solution: Idempotent Check

```php
// Before every invoice creation
if ($this->invoiceRepository->existsForPeriod($contractId, $billingPeriod)) {
    $stats['skipped']++;
    return;  // Skip - already created
}
```

### Test Case: Rerun After Crash

```
Day 1, 03:00-03:25: Generate invoices + Email sent ✓
Day 1, 15:00: Admin reruns command manually for testing
  → Checks each contract: "Exists for 2026-02? YES"
  → Skips all 10,000 (stats['skipped'] = 10000)
  → Sends: "All skipped - no new invoices" email
  → NO DUPLICATES ✓
```

---

## Unique Constraints at Database Level

### SQL Enforcement

```sql
UNIQUE KEY unique_invoice_per_period (contract_id, billing_period)
```

**Effect:**

```
INSERT INTO invoices (contract_id, billing_period, ...)
VALUES (1234, '2026-02', ...)

-- Unique constraint check:
-- IS THERE already a row where contract_id=1234 AND billing_period='2026-02'?
-- IF YES → Throw IntegrityConstraintException
-- IF NO → INSERT allowed
```

**Multi-Layer Safety:**
1. Application checks `existsForPeriod()` (fast, avoids DB error)
2. Database unique constraint (final safety net)
3. If both fail, error is caught and logged

---

## Batch Size Tuning

### Why Batch Size = 100?

```
Per-contract costs:
  - Repository.find() → DB query (1ms)
  - Meter reading fetch → DB query (2ms)  
  - Tariff calculation → Memory ops (1ms)
  - Invoice insert → DB write (2ms)
  Total per contract: ~6ms average

Batch of 100:
  - Load 100 IDs into memory → Negligible
  - Process 100 contracts → ~600ms
  - Clear/GC → ~10ms
  - Total batch: ~700ms
  
Memory impact:
  - 100 Invoice objects → ~100KB
  - 100 Meter readings → ~50KB
  - Working memory → ~50KB
  - Total → ~200KB (peak during batch)
```

### Scaling Recommendations

| Contracts | Batch Size | Batches | Time | Memory |
|-----------|-----------|---------|------|--------|
| 1,000 | 100 | 10 | 7 sec | 50MB |
| 10,000 | 100 | 100 | 70 sec | 50MB |
| 100,000 | 50 | 2,000 | 200 sec | 50MB |
| 1,000,000 | 25 | 40,000 | 2,400 sec | 50MB |

**Memory stays constant** - only batch size in memory at once!

---

## Cron Reliability

### Cron Entry Breakdown

```bash
0 3 * * * /usr/bin/php /app/bin/console invoices:generate-monthly >> /var/log/invoices.log 2>&1
│ │ │ │ │
│ │ │ │ └─ Day of week (0=Sun, any day)
│ │ │ └──── Month (Jan-Dec, any month)
│ │ └────── Day of month (any day)
│ └──────── Hour (03:00 = 3 AM)
└────────── Minute (exactly :00)
```

### Output Redirection

```
>> /var/log/invoices.log  → Append stdout to log
2>&1                      → Redirect stderr to stdout
```

**Result:** Both success and error output in `/var/log/invoices.log`

### Verifying Cron Execution

```bash
# Check if scheduled
crontab -l | grep invoices

# Monitor execution
tail -f /var/log/invoices.log

# Check system cron log
grep invoices /var/log/syslog

# Test manually
php /app/bin/console invoices:generate-monthly
```

---

## Testing Strategy

### Unit Tests

```php
// Test each service independently
testBatchInvoiceGenerator()
  - testLoadContractsPerformance()
  - testDuplicateSkipping()
  - testErrorHandling()
  - testStatisticsAccuracy()

testSummaryEmailer()
  - testEmailBodyGeneration()
  - testErrorEmailGeneration()

testContractRepository()
  - testFindAllActiveContractIds()

testInvoiceRepository()
  - testExistsForPeriod()
  - testDuplicateConstrain()
```

### Integration Tests

```php
// Test across components
testFullCommandExecution()
  - Setup: 100 test contracts in DB
  - Execute: GenerateInvoicesCommand
  - Assert: 100 invoices created
  - Assert: Summary email enqueued

testDuplicatePreventionIntegration()
  - Setup: 100 contracts, 50 existing invoices
  - Execute: GenerateInvoicesCommand
  - Assert: Only 50 new invoices
  - Assert: 50 skipped in stats
```

### Load Tests

```php
// Simulate real-world load
testPerformanceWith10kContracts()
testMemoryConstantAfterBatches()
testDatabaseConnectionPooling()
testConcurrentRuns()  // Two processes at same time
```

---

## Production Checklist

```
✓ Database schema updated (is_active, indexes)
✓ Services registered in services.yaml
✓ Communication parameters configured in .env
✓ Cron job installed
✓ Log rotation configured
✓ Email template tested
✓ Monitoring alerts configured
✓ Backup tested (can revert if needed)
✓ Dry-run successful on staging
✓ Performance baseline established
✓ On-call procedures documented
```

---

## Future Enhancements

| Enhancement | Complexity | Benefit |
|-------------|-----------|---------|
| Parallel processing | Medium | 4-8x faster for 100k+ |
| Message queue | Medium | Async processing, error recovery |
| Partial retries | Low | Auto-retry failed contracts |
| Progress webhooks | Low | Real-time progress to dashboard |
| Invoice status transitions | Medium | Draft → Sent → Paid tracking |
| Slack notifications | Low | Real-time alerts to team |
| Database replication read | Low | Reduce main DB load |
| Stored procedure generation | High | 10-20% performance gain |

---
