# EXERCISE 2.1 Detailed Issue Analysis

## Complete Breakdown of Original Code Problems

---

## 🔴 ISSUE #1: SQL Injection - Line 18 (contractId)

### Location
```php
$contract = $this->db->query(
    "SELECT c.*, t.code as tariff_code, t.price_per_kwh, t.fixed_monthly
     FROM contracts c JOIN tariffs t ON c.tariff_id = t.id
     WHERE c.id = $contractId"  // ← VULNERABLE
)->fetch();
```

### What's Wrong
Variable `$contractId` is directly concatenated into SQL string without escaping.

### Security Risk
```php
// Attacker crafts input
$contractId = "1 OR 1=1";

// SQL becomes:
// SELECT c.*, t.code... FROM contracts c JOIN tariffs t... WHERE c.id = 1 OR 1=1
// This returns ALL contracts, not just one!

$contractId = "1; DROP TABLE contracts; --";
// SQL becomes:
// SELECT c.*, ... WHERE c.id = 1; DROP TABLE contracts; --
// Deletes entire table!
```

### Impact
- **Data Breach:** Unauthorized access to contracts
- **Data Loss:** Ability to delete/modify data
- **OWASP Risk:** Rank #3 in Top 10 (2021)
- **Regulatory:** GDPR/CCPA compliance failure

### Severity
⚠️ **CRITICAL** - This is exploitable in production

---

## 🔴 ISSUE #2: SQL Injection - Line 29 (month)

### Location
```php
$readings = $this->db->query(
    "SELECT SUM(kwh_consumed) as total
     FROM meter_readings
     WHERE contract_id = $contractId
     AND FORMAT(reading_date, 'yyyy-MM') = '$month'"  // ← VULNERABLE
)->fetch();
```

### What's Wrong
Both `$contractId` and `$month` are concatenated without parameterization.

### Security Risk
```php
// Attacker input
$month = "2026-03' OR '1'='1";

// SQL becomes:
// ... AND FORMAT(reading_date, 'yyyy-MM') = '2026-03' OR '1'='1'
// Always true! Gets all readings.

$month = "2026-03'; DROP TABLE meter_readings; --";
// Destroys data
```

### Impact
- **Information Disclosure:** Access to other contracts' meter data
- **Data Destruction:** Can delete meter readings
- **Business Impact:** Billing system compromise

### Severity
⚠️ **CRITICAL** - Directly affects core business data

---

## 🔴 ISSUE #3: SQL Injection - Line 48 (INSERT)

### Location
```php
$this->db->query(
    "INSERT INTO invoices (contract_id, billing_period, total_kwh, total_amount, status)
     VALUES ($contractId, '$month', $totalKwh, $total, 'draft')"  // ← VULNERABLE
);
```

### What's Wrong
3 of 4 values are vulnerable to injection:
- `$contractId` (integer but unescaped)
- `$month` (string)
- `$totalKwh` and `$total` (numbers but potential type juggling)

### Security Risk
```php
$contractId = "1, 2, 3); DELETE FROM invoices; --";

// SQL becomes:
// INSERT INTO invoices (...) VALUES (1, 2, 3); DELETE FROM invoices; -- ...)
// Deletes all invoices after inserting
```

### Impact
- **Data Corruption:** Inserting false invoices
- **Data Loss:** Deleting legitimate invoices
- **Audit Trail Loss:** Destroying billing history

### Severity
⚠️ **CRITICAL** - Affects financial/audit system

---

## 🔴 ISSUE #4: Using echo for Error Messages (Line 24)

### Location
```php
if (!$contract) {
    echo "Contract not found";  // ← BAD PRACTICE
    return false;
}
```

### What's Wrong
1. **Unprofessional** - Direct output to stdout
2. **Unmaintainable** - Error message mixed with code
3. **Uncatchable** - Can't be accessed by caller
4. **Unloggable** - Can't be stored in logs
5. **API Breaking** - Outputs text to stdout (breaks JSON APIs)

### Example Failure
```php
// This is the original class usage
$result = $calculator->calculate(123, '2026-03');

// If contract not found, output "Contract not found" appears in response
// If it's a JSON API expecting:
// { "status": "success", "total": 100 }

// Instead, client gets:
// Contract not found{ "status": "success", "total": null }
// OR
// Contract not found

// Client parser breaks! JSON is invalid.
```

### API Response When Contract Not Found
```
HTTP/1.1 200 OK
Content-Type: application/json

Contract not found{  /* Invalid JSON! */
    "status": "success"
}
```

### Impact
- **API Compatibility** - Breaks JSON responses
- **Error Visibility** - Errors lost in logs
- **Testing** - Can't validate error cases
- **Debugging** - No stack trace

### Severity
⚠️ **HIGH** - Breaks integration with other systems

---

## 🔴 ISSUE #5: Using echo for Error Messages (Line 41)

### Location
```php
} else {
    echo "Unknown tariff type";  // ← BAD PRACTICE
    return false;
}
```

### What's Wrong
Same as Issue #4, plus:
- Called at a critical business logic point
- Prevents proper tariff processing
- No way to know which tariff caused error

### Example
```php
// User has a tariff with code 'PREMIUM_INDEX'
// Code only recognizes 'INDEX'
// Result: "Unknown tariff type" echoed
// Invoice never created
// User never finds out why

// In logs, you just see a failed request
// No way to know it's tariff code mismatch
```

### Impact
- **Silent Failures** - Invoices not created without clear reason
- **Bad UX** - Users don't know what went wrong
- **Support Burden** - Support can't debug issues

### Severity
⚠️ **HIGH** - Critical business process failure

---

## 🔴 ISSUE #6: Inconsistent Return Types

### Location
```php
if (!$contract) {
    echo "Contract not found";
    return false;  // ← Sometimes false
}

// ...

echo "Invoice created: $total EUR";
return $total;  // ← Sometimes float
```

### What's Wrong
Method can return:
- `false` (boolean) - when error
- `float` - when success

### Problem in Consumer Code
```php
$total = $calculator->calculate(123, '2026-03');

// Now we have three cases to handle:
if ($total === false) {
    // Error occurred, but we don't know what error
    // Contract not found? Tariff unknown? API failure?
}

if ($total === 0) {
    // Error or customer used 0 kWh? Can't tell!
}

if ($total > 0) {
    // Success - but what if billing system expects 0?
}

// What about this case?
$total = 0;  // Legitimate: flat rate with no consumption
// But code above would treat it as success
```

### Example Confusion
```php
// Scenario 1: Success with zero monthly charge
$total = $calculator->calculate(123, '2026-03');  // Returns 0.0 (flat rate)
if ($total === false) { ... }  // Won't trigger
if ($total >= 0) { /* save */ }  // Saves correctly

// Scenario 2: Error case
$total = $calculator->calculate(999, '2026-03');  // Contract not found
if ($total === false) { ... }  // Triggers correctly
if ($total >= 0) { /* save */ }  // Won't execute

// But what if...
public function somewhereElse() {
    $total = $calculator->calculate(...);
    if (!$total) { /* error */ }  // Treats 0.0 as error!
    return $total + 10;  // Returns 10, losing context
}
```

### Impact
- **Error Prone** - Impossible to properly handle errors
- **Silent Bugs** - Easy to confuse success with failure
- **Type Unsafe** - Type hint would conflict (float|bool?)

### Severity
⚠️ **HIGH** - Leads to subtle bugs

---

## 🔴 ISSUE #7: Giant if/elseif Chain (Lines 34-45)

### Location
```php
if (strpos($contract['tariff_code'], 'FIX') !== false) {
    $amount = $totalKwh * $contract['price_per_kwh'];
    $amount += $contract['fixed_monthly'];
    if ($contract['tariff_code'] == 'FIX_PROMO') {
        $amount = $amount * 0.9;
    }
} elseif (strpos($contract['tariff_code'], 'INDEX') !== false) {
    $spotPrice = file_get_contents(...);
    $spotData = json_decode($spotPrice, true);
    $amount = $totalKwh * $spotData['avg_price'];
    $amount += $contract['fixed_monthly'];
    if ($totalKwh > 500) {
        $amount = $amount * 0.95;
    }
} elseif ($contract['tariff_code'] == 'FLAT_RATE') {
    $amount = $contract['fixed_monthly'];
} else {
    echo "Unknown tariff type";
    return false;
}
```

### What's Wrong
1. **Not Extensible** - Adding new tariff requires modifying `calculate()`
2. **Mixed Concerns** - Each branch has different logic structure
3. **Hard to Test** - Can't test one tariff logic without entire method
4. **Duplicate Logic** - Multiple branches calculate similar things
5. **Magic Numbers** - Hardcoded 500, 0.9, 0.95

### How to Add New Tariff (TIME_OF_USE)
```php
// Current code requires:
if (strpos($contract['tariff_code'], 'FIX') !== false) {
    // existing code
} elseif (strpos($contract['tariff_code'], 'INDEX') !== false) {
    // existing code
} elseif ($contract['tariff_code'] == 'FLAT_RATE') {
    // existing code
} elseif ($contract['tariff_code'] == 'TIME_OF_USE') {  // ← Add here
    // TIME_OF_USE logic
    // ...
    // BUT if there's a bug in fix logic, it affects all tariffs!
    // Cross-contamination risk
}

// Changes required:
// 1. Edit calculate() method
// 2. Risk breaking other tariffs
// 3. Requires full regression testing
// 4. Release management complexity
```

### Violates SOLID
- ❌ **Open/Closed Principle** - Not open for extension without modification
- ❌ **Single Responsibility** - Method does too much
- ❌ **Liskov Substitution** - Can't use polymorphism

### Impact
- **Total Cost of Change** - Adding 1 new tariff requires testing 4+ tariffs
- **Maintenance Burden** - Risk multiplies with each tariff
- **Team Velocity** - Simple feature takes longer due to regression risk
- **Quality** - More changes = more bugs

### Test Cases Impacted
To add TIME_OF_USE safely, you must re-test:
- ✅ TIME_OF_USE (new)
- ✅ FIX (might have broken it)
- ✅ FIX_PROMO (might have broken it)
- ✅ INDEX (might have broken it)
- ✅ FLAT_RATE (might have broken it)

**5 test scenarios for 1 new feature!**

### Severity
⚠️ **HIGH** - Unmaintainable at scale

---

## 🔴 ISSUE #8: Tight Coupling to Raw Database Object

### Location
```php
class InvoiceCalculator {
    private $db;  // ← Raw database object

    public function __construct($db) {
        $this->db = $db;
    }

    public function calculate($contractId, $month) {
        $contract = $this->db->query(...);  // Uses raw DB
        $readings = $this->db->query(...);  // Uses raw DB
        $this->db->query("INSERT INTO...");  // Uses raw DB
    }
}
```

### What's Wrong
1. **Testing Impossible** - Can't mock $db without real database
2. **Unclear Dependencies** - What DB operations are needed?
3. **Too Much Power** - Can do ANY SQL operation
4. **Tight Coupling** - If DB interface changes, code breaks
5. **No Separation** - Mixing data access with business logic

### Testing Problem
```php
// To test calculate(), we must:
$pdo = new PDO('sqlsrv:...');  // ← Real database
$calculator = new InvoiceCalculator($pdo);

// Now each test:
// 1. Creates real contracts in DB
// 2. Creates real meter readings
// 3. Inserts real invoices
// 4. Leaves test data in production DB
// 5. Test failures corrupt real data

// Tests become slow (1s each instead of 1ms)
// Tests become flaky (depends on DB state)
// Tests are fragile (schema changes break them)
```

### Lack of Clarity
```php
// What does this class need from the database?
// Looking at the constructor:
public function __construct($db) { }
// You can't tell!
// You think: "Maybe it only reads contracts?"
// But it also: reads tariffs, reads meter readings, writes invoices

// If we used interfaces:
public function __construct(
    ContractRepository $contracts,
    MeterReadingRepository $readings,
    InvoiceRepository $invoices
) { }
// Now it's 100% clear what data it needs
```

### Impact
- **Testing** - Integration tests only, very slow
- **Quality** - Same bug appears in many places
- **Debugging** - Hard to isolate issues
- **Refactoring** - Can't safely refactor

### Severity
⚠️ **HIGH** - Prevents professional testing

---

## 🔴 ISSUE #9: No Error Handling for External API

### Location
```python
$spotPrice = file_get_contents(
    "https://api.energy-market.eu/spot?month=$month"  // ← No error handling
);
$spotData = json_decode($spotPrice, true);  // ← No validation
$amount = $totalKwh * $spotData['avg_price'];  // ← Can fail silently
```

### What's Wrong
1. **No Timeout** - Can hang forever waiting for API
2. **No Error Handling** - Doesn't check if request failed
3. **No Validation** - Doesn't check if JSON is valid
4. **Silent Failures** - Errors become NaN or PHP warnings

### Failure Scenarios

#### Scenario 1: API Timeout
```
1. API server is down
2. file_get_contents() hangs (no timeout)
3. Request times out after default PHP timeout (300 seconds!)
4. $spotPrice = false
5. json_decode(false) = null
6. $spotData['avg_price'] = Undefined index warning
7. $amount = 250 * null = NaN
8. Invoice created with NaN total
9. Billing system crashes processing invoice
```

**Result:** Customer sees invoice for NaN EUR. System breaks.

#### Scenario 2: API Returns Invalid JSON
```
1. API server has bug, returns HTML error page
2. file_get_contents() = "<html>500 Server Error...</html>"
3. json_decode() returns null
4. $spotData = null
5. $spotData['avg_price'] = Undefined index warning
6. $amount = NaN
7. Invoice created with NaN
```

**Result:** Silent corruption of data

#### Scenario 3: API Returns Valid JSON But Missing Field
```
1. API response: {"status": "error", "message": "Service unavailable"}
2. json_decode() = {"status": "error"}
3. json_decode($response, true)['avg_price'] = Undefined key
4. PHP Warning: Undefined array key 'avg_price'
5. $amount = 250 * undefined = NaN
6. Invoice still created!
```

**Result:** Invoices with fake amounts

### Real-World Impact
```
Date: 2026-03-15
Energy-Market API goes down due to datacenter outage.

2:15 PM - API stops responding
2:15-2:45 PM - 300 invoices are created with NaN totals
2:45 PM - First customer calls about invoice for "NaN EUR"
2:50 PM - Support realizes 300 invoices are corrupted
3:00 PM - Must refund all customers
3:30 PM - Critical incident post-mortem
4:00 PM - Sales calls to apologize to key customers
5:00 PM - Manual fixes to 300 invoices required
6:00 PM - Reputation damage on social media
7:00 PM - 6 hours of team time spent firefighting
```

### Best Practices Not Followed
- No timeout set (should be 5-10 seconds max)
- No try/catch (should wrap or check return)
- No validation (should verify response structure)
- No logging (can't debug production issues)
- No retry logic (API might be temporarily down)

### Impact
- **Reliability** - System fails when external services fail
- **Data Quality** - Corrupt data in invoices
- **Debugging** - Can't see what happened
- **Security** - Silent failures hide attacks
- **Compliance** - Invalid invoices violate regulations

### Severity
⚠️ **CRITICAL** - Can corrupt entire billing system

---

## 🔴 ISSUE #10: No Logging or Observability

### Location
```php
public function calculate($contractId, $month) {
    // ... code that fails ...
    echo "Contract not found";
    return false;
}
```

### What's Wrong
1. **No Logging** - Errors aren't recorded
2. **No Traceability** - Can't trace requests through system
3. **No Metrics** - Don't know success/failure rates
4. **No Debugging** - Production issues can't be investigated

### Real Scenario
```
📞 Customer Support Call
Customer: "I created an invoice for contract 12345 but it failed. Why?"

Support: "Let me check the logs..."
[Searches production logs]

Support: "I don't see anything. Did you see anything on your screen?"
Customer: "Nothing, the page just returned without creating an invoice"

Support: "Let me contact Engineering..."
[Engineering investigates]

Engineering: "We have no logs. Could be anything:"
- Contract not found
- Tariff unknown  
- API failure
- Database error
- Timeout

"We'd need to manually check each possibility, which takes 2-3 hours"

Result: 
✉️ Investigation takes hours
😞 Customer frustrated  
💰 Support costs increase
📊 No visibility into real issue
```

### What Good Logging Would Show
```
[2026-03-15 14:32:45] INFO: Invoice calculation started 
    contract_id: 12345
    month: 2026-03

[2026-03-15 14:32:45] INFO: Contract loaded
    tariff_code: FIX_PROMO
    fixed_monthly: 25.00

[2026-03-15 14:32:45] INFO: Meter readings fetched
    total_kwh: 250.0

[2026-03-15 14:32:45] INFO: Tariff calculated
    tariff_type: FIX_PROMO
    amount: 45.00

[2026-03-15 14:32:45] INFO: Tax applied
    country: ES
    tax_rate: 0.21
    tax_amount: 9.45

[2026-03-15 14:32:45] INFO: Invoice saved
    invoice_id: 98765
    total_amount: 54.45
    status: draft

[2026-03-15 14:32:46] INFO: Invoice creation completed successfully
    duration: 1.2s
    total_amount: 54.45
```

Now support can see exactly what happened in 10 seconds!

### Impact
- **Debugging** - Impossible to diagnose production issues
- **Metrics** - Can't measure performance or failures
- **Auditability** - No record of what invoices were created
- **Compliance** - Financial records lack traceability
- **Support** - Can't help customers efficiently

### Severity
⚠️ **MEDIUM-HIGH** - Silent operation without visibility

---

## 🔴 ISSUE #11: No Input Validation

### Location
```php
public function calculate($contractId, $month) {
    // No validation that:
    // - $contractId is positive integer
    // - $month is in YYYY-MM format  
    // - $month is not in future
    // - Contract actually exists
}
```

### What's Wrong
1. **No Type Safety** - Parameters not validated
2. **No Business Rule Checks** - Can create invoices for future months
3. **No Defensive Programming** - Assumes inputs are correct

### Attack Vectors

#### Negative Contract ID
```php
$calculator->calculate(-1, '2026-03');
// Might return a contract if query is poorly written
// Or might silently fail
```

#### Invalid Month Format
```php
$calculator->calculate(123, '2026/03');  // Wrong separator
$calculator->calculate(123, '2026-13');  // Invalid month
$calculator->calculate(123, '9999-99');  // Nonsense
// Code might fail at various points, hard to predict where
```

#### Future Month
```php
$calculator->calculate(123, '2999-03');  
// Creates invoice for year 2999? 
// Or should this be rejected?
// No validation, so behavior is undefined
```

#### Null Inputs
```php
$calculator->calculate(null, null);
// Might work or crash depending on query
```

### Impacts
- **Garbage In, Garbage Out** - Bad data creates bad invoices
- **Debugging** - Hard to trace where bad data came from
- **Security** - Opens door to unexpected behavior
- **Maintainability** - Assumptions about inputs hidden

### Severity
⚠️ **MEDIUM** - Allows invalid state

---

## 📊 Issue Summary Table

| # | Issue | Type | Severity | Fix |
|---|-------|------|----------|-----|
| 1 | SQL Injection (contractId) | Security | **CRITICAL** | Parameterized queries |
| 2 | SQL Injection (month) | Security | **CRITICAL** | Parameterized queries |
| 3 | SQL Injection (INSERT) | Security | **CRITICAL** | Parameterized queries |
| 4 | echo for errors (line 24) | Error Handling | HIGH | Exceptions |
| 5 | echo for errors (line 41) | Error Handling | HIGH | Exceptions |
| 6 | Inconsistent return types | Design | HIGH | Return objects |
| 7 | Giant if/elseif chain | Maintainability | HIGH | Strategy pattern |
| 8 | Tight coupling to DB | Architecture | HIGH | Dependency injection |
| 9 | No API error handling | Reliability | **CRITICAL** | Try/catch + validation |
| 10 | No logging | Observability | MEDIUM-HIGH | PSR-3 logger |
| 11 | No input validation | Robustness | MEDIUM | Validation layer |

---

## 🎯 Total Impact Assessment

### Security Risk Level: 🔴 **CRITICAL**
- 3 SQL Injection vulnerabilities
- 1 API error handling vulnerability
- **OWASP Top 10 violation:** #1 (Injection), #3 (Authentication)

### Maintainability Risk Level: 🔴 **CRITICAL**
- Cannot safely add new tariffs
- Cannot safely modify existing code
- Code duplication increases with each tariff

### Reliability Risk Level: 🔴 **CRITICAL**
- No error handling for external APIs
- Silent failures corrupt data
- No observability for debugging

### Testing Risk Level: 🔴 **CRITICAL**
- Cannot test without production database
- No way to mock dependencies
- Integration tests are fragile

### Overall Assessment
⚠️ **NOT PRODUCTION READY** ⚠️

This code would NOT pass security review in any serious organization. It needs comprehensive refactoring before deployment.
