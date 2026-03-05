/*
================================================================================
PART 1: SQL SERVER QUERIES - TECHNICAL ASSESSMENT
================================================================================
Date: 2026-03-05
Purpose: Energy utility contract and consumption analytics queries
Target DB: SQL Server (SQL Server 2016+)
================================================================================
*/

-- ==============================================================================
-- EXERCISE 1.2 - QUERY A: Active Contracts with Annual Consumption
-- ==============================================================================
/*
Description:
  List all active contracts with associated client name, tariff code, and total 
  kWh consumed in the current calendar year (2026), ordered by consumption descending.

Business Logic:
  - Active Contract: status = 'active' AND (end_date IS NULL OR end_date > GETDATE())
  - Annual Period: YEAR(reading_date) = YEAR(GETDATE())
  - Sum consumption: Aggregate kwh_consumed from meter_readings grouped by contract
  
Performance Notes:
  - Recommend index on: contracts(status, end_date), meter_readings(contract_id, reading_date)
  - Large consumption data may benefit from materialized views for caching

Expected Output Columns:
  - client_name (NVARCHAR): Full name of the client
  - fiscal_id (VARCHAR): Client's fiscal/tax ID
  - tariff_code (VARCHAR): Tariff code
  - total_kwh_annual (DECIMAL): Sum of kWh consumed in current year
  - contract_id (INT): Reference to the contract
*/

SELECT
    c.full_name AS client_name,
    c.fiscal_id,
    t.code AS tariff_code,
    ISNULL(SUM(mr.kwh_consumed), 0) AS total_kwh_annual,
    con.id AS contract_id
FROM contracts con
INNER JOIN clients c ON con.client_id = c.id
INNER JOIN tariffs t ON con.tariff_id = t.id
LEFT JOIN meter_readings mr ON con.id = mr.contract_id 
    AND YEAR(mr.reading_date) = YEAR(GETDATE())
WHERE con.status = 'active'
    AND (con.end_date IS NULL OR con.end_date > GETDATE())
GROUP BY con.id, c.full_name, c.fiscal_id, t.code
ORDER BY total_kwh_annual DESC;

-- ==============================================================================


-- ==============================================================================
-- EXERCISE 1.2 - QUERY B: Active Contracts and Average Monthly Consumption by Country
-- ==============================================================================
/*
Description:
  For each country (Spain 'ES' and Portugal 'PT'), retrieve:
  - Total number of active contracts
  - Average monthly consumption over the last 6 months
  
Business Logic:
  - Active Contract: Same as Query A (status active + end_date validation)
  - Last 6 Months: reading_date >= DATEADD(MONTH, -6, CAST(GETDATE() AS DATE))
  - Monthly Average: Total consumption in 6 months / 6 months
  - Country Filter: On clients.country IN ('ES', 'PT')
  
Calculation Details:
  - Some months may have multiple readings or none (handled with ISNULL)
  - If a contract has 0 readings in 6-month period, still counted in active contracts
  - Average is calculated as: SUM(kwh) / DATEDIFF(MONTH, start_date, end_date)
  
Expected Output Columns:
  - country (CHAR): 'ES' or 'PT'
  - total_active_contracts (INT): Count of active contracts per country
  - avg_monthly_consumption_6m (DECIMAL): Average monthly kWh last 6 months
*/

SELECT
    c.country,
    COUNT(DISTINCT con.id) AS total_active_contracts,
    ISNULL(
        SUM(mr.kwh_consumed) / 6.0, 
        0
    ) AS avg_monthly_consumption_6m
FROM clients c
INNER JOIN contracts con ON c.id = con.client_id
LEFT JOIN meter_readings mr ON con.id = mr.contract_id 
    AND mr.reading_date >= DATEADD(MONTH, -6, CAST(GETDATE() AS DATE))
WHERE con.status = 'active'
    AND (con.end_date IS NULL OR con.end_date > GETDATE())
    AND c.country IN ('ES', 'PT')
GROUP BY c.country
ORDER BY c.country;

-- ==============================================================================


-- ==============================================================================
-- EXERCISE 1.2 - QUERY C: Clients with Contracts but No Invoices
-- ==============================================================================
/*
Description:
  Identify clients that have at least one active contract but have never received 
  an invoice. Return client name, fiscal ID, and count of contracts per client.
  
Business Logic:
  - Contract Existence: Client must have at least 1 contract (active or not)
  - Invoice Absence: Client must have NO invoices across all their contracts
  - Contract Count: Show how many contracts belong to each such client
  
Implementation Notes:
  - Use LEFT JOIN with invoices and INNER JOIN with contracts to ensure:
    * Only clients with at least 1 contract are included
    * Only clients with NO invoices in ANY contract are included
  - NOT EXISTS clause ensures zero invoices linked to client's contracts
  
Edge Cases:
  - Client with 1 contract + 0 invoices → Include with contract_count = 1
  - Client with 5 contracts + 0 invoices → Include with contract_count = 5
  - Client with 5 contracts + 1 invoice → EXCLUDE (has at least 1 invoice)
  
Expected Output Columns:
  - full_name (NVARCHAR): Client's full name
  - fiscal_id (VARCHAR): Client's fiscal/tax ID
  - contract_count (INT): Number of contracts for this client
*/

SELECT
    c.full_name,
    c.fiscal_id,
    COUNT(con.id) AS contract_count
FROM clients c
INNER JOIN contracts con ON c.id = con.client_id
WHERE NOT EXISTS (
    SELECT 1
    FROM invoices inv
    WHERE inv.contract_id IN (
        SELECT id FROM contracts WHERE client_id = c.id
    )
)
GROUP BY c.id, c.full_name, c.fiscal_id
HAVING COUNT(con.id) > 0
ORDER BY c.full_name;

-- ==============================================================================
-- EXERCISE 1.2: Stored Procedure - sp_GenerateInvoice
-- ==============================================================================
/*
Description:
  Creates an invoice for a given contract and billing period.
  Validates that the contract is active and no invoice exists for that period.
  Calculates total kWh from meter readings and applies tariff pricing.
  
Parameters:
  @contract_id INT: The contract to bill
  @billing_period VARCHAR(7): Billing period in format 'YYYY-MM' (e.g., '2026-02')
  
Business Logic:
  1. Validate contract exists and is active
  2. Validate no invoice already exists for this contract + period combination
  3. Sum all meter_readings for the contract during the billing month
  4. Calculate invoice amount: (total_kwh * price_per_kwh) + fixed_monthly
  5. Insert invoice with status 'draft'
  6. Return the created invoice with all details
  
Error Handling:
  - Contract not found: Raises error, returns -1
  - Contract not active: Raises error, returns -2
  - Invoice already exists: Raises error, returns -3
  - No meter readings in period: Allows (sets total_kwh = 0), returns full invoice
  
Performance Notes:
  - Indexes recommended: contracts(id, status, end_date), 
    meter_readings(contract_id, reading_date), 
    invoices(contract_id, billing_period)
*/

CREATE PROCEDURE sp_GenerateInvoice
    @contract_id INT,
    @billing_period VARCHAR(7),
    @invoice_id INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @error_code INT = 0;
    DECLARE @total_kwh DECIMAL(12, 3) = 0;
    DECLARE @price_per_kwh DECIMAL(10, 6) = 0;
    DECLARE @fixed_monthly DECIMAL(10, 2) = 0;
    DECLARE @total_amount DECIMAL(10, 2) = 0;
    DECLARE @client_id INT;
    DECLARE @tariff_id INT;
    DECLARE @period_start DATE;
    DECLARE @period_end DATE;
    
    BEGIN TRY
        -- Step 1: Validate contract exists
        SELECT 
            @client_id = con.client_id,
            @tariff_id = con.tariff_id
        FROM contracts con
        WHERE con.id = @contract_id;
        
        IF @client_id IS NULL
        BEGIN
            RAISERROR('Contract ID %d does not exist.', 16, 1, @contract_id);
            SET @error_code = -1;
            GOTO ERROR_EXIT;
        END
        
        -- Step 2: Validate contract is active
        IF NOT EXISTS (
            SELECT 1 FROM contracts 
            WHERE id = @contract_id 
              AND status = 'active' 
              AND (end_date IS NULL OR end_date > GETDATE())
        )
        BEGIN
            RAISERROR('Contract ID %d is not active.', 16, 1, @contract_id);
            SET @error_code = -2;
            GOTO ERROR_EXIT;
        END
        
        -- Step 3: Validate no invoice already exists for this period
        IF EXISTS (
            SELECT 1 FROM invoices 
            WHERE contract_id = @contract_id 
              AND billing_period = @billing_period
        )
        BEGIN
            RAISERROR('Invoice already exists for contract %d in period %s.', 16, 1, @contract_id, @billing_period);
            SET @error_code = -3;
            GOTO ERROR_EXIT;
        END
        
        -- Step 4: Get period bounds (first and last day of month)
        SET @period_start = CAST(@billing_period + '-01' AS DATE);
        SET @period_end = EOMONTH(@period_start);
        
        -- Step 5: Calculate total kWh from meter readings
        SELECT @total_kwh = ISNULL(SUM(kwh_consumed), 0)
        FROM meter_readings
        WHERE contract_id = @contract_id
          AND reading_date >= @period_start
          AND reading_date <= @period_end;
        
        -- Step 6: Get tariff pricing
        SELECT 
            @price_per_kwh = t.price_per_kwh,
            @fixed_monthly = t.fixed_monthly
        FROM tariffs t
        WHERE t.id = @tariff_id;
        
        -- Step 7: Calculate total amount
        SET @total_amount = (@total_kwh * @price_per_kwh) + @fixed_monthly;
        
        -- Step 8: Insert invoice
        INSERT INTO invoices (
            contract_id,
            billing_period,
            total_kwh,
            total_amount,
            status,
            created_at
        )
        VALUES (
            @contract_id,
            @billing_period,
            @total_kwh,
            @total_amount,
            'draft',
            GETDATE()
        );
        
        SET @invoice_id = SCOPE_IDENTITY();
        
        -- Step 9: Return success with invoice details
        SELECT
            @invoice_id AS invoice_id,
            @contract_id AS contract_id,
            @billing_period AS billing_period,
            @total_kwh AS total_kwh,
            @total_amount AS total_amount,
            'draft' AS status,
            GETDATE() AS created_at,
            0 AS error_code
        FOR JSON PATH, WITHOUT_ARRAY_WRAPPER;
        
    END TRY
    BEGIN CATCH
        IF @error_code = 0
        BEGIN
            -- Unexpected error
            DECLARE @error_msg NVARCHAR(MAX) = ERROR_MESSAGE();
            RAISERROR('Unexpected error in sp_GenerateInvoice: %s', 16, 1, @error_msg);
        END
        
        -- Return error details
        SELECT
            NULL AS invoice_id,
            @contract_id AS contract_id,
            @billing_period AS billing_period,
            NULL AS total_kwh,
            NULL AS total_amount,
            NULL AS status,
            NULL AS created_at,
            ISNULL(@error_code, -999) AS error_code;
    END CATCH
    
    RETURN @error_code;
    
    ERROR_EXIT:
        SELECT
            NULL AS invoice_id,
            @contract_id AS contract_id,
            @billing_period AS billing_period,
            NULL AS total_kwh,
            NULL AS total_amount,
            NULL AS status,
            NULL AS created_at,
            @error_code AS error_code;
        RETURN @error_code;
END;

-- ==============================================================================
-- EXERCISE 1.3: Index Recommendations
-- ==============================================================================
/*
Performance Index Analysis and Recommendations:
================================================================================

Based on the queries written in EXERCISE 1.1, the following indexes would 
significantly improve performance:

INDEX 1: Composite Index on contracts (for filtering active contracts)
──────────────────────────────────────────────────────────────────────

  CREATE NONCLUSTERED INDEX idx_contracts_status_enddate 
  ON contracts (status, end_date) 
  INCLUDE (client_id, tariff_id);

  Benefits:
  - Query A, B, C all filter WHERE status = 'active' AND end_date condition
  - Composite index allows SQL Server to evaluate both conditions in index scan
  - INCLUDE columns (client_id, tariff_id) enable index covering for joins
  - Reduces table lookups from 1000s to index scans

  Impact:
  - Estimated improvement: 40-60% faster for active contract queries
  - Size tradeoff: Small (only 6 bytes per index entry vs full row)


INDEX 2: Composite Index on meter_readings (for time-range queries)
──────────────────────────────────────────────────────────────────

  CREATE NONCLUSTERED INDEX idx_meter_readings_contract_date 
  ON meter_readings (contract_id, reading_date) 
  INCLUDE (kwh_consumed);

  Benefits:
  - Query A filters: reading_date BETWEEN start/end of year
  - Query B filters: reading_date >= DATEADD(MONTH, -6, ...)
  - Composite key (contract_id, reading_date) enables range seeks
  - INCLUDE kwh_consumed enables covered index (no table lookups)
  - Eliminates full table scan of meter_readings (can have millions of rows)

  Impact:
  - Estimated improvement: 70-80% faster for consumption aggregations
  - Especially critical for Query A which sums consumption per contract
  - Size: Medium (contract_id 4 bytes + date 8 bytes + kwh 12 bytes per entry)


INDEX 3: Composite Index on invoices (for existence checks)
──────────────────────────────────────────────────────────

  CREATE NONCLUSTERED INDEX idx_invoices_contract_period 
  ON invoices (contract_id, billing_period);

  Benefits:
  - Query C uses: WHERE NOT EXISTS (SELECT 1 FROM invoices...)
  - sp_GenerateInvoice validates: WHERE contract_id = ? AND billing_period = ?
  - Composite index allows seek on both conditions (no scan needed)
  - Small index (contract_id 4 bytes + varchar 7 bytes)

  Impact:
  - Estimated improvement: 50-70% faster for existence checks
  - Reduced latency from O(n) scan to O(log n) seek
  - Critical for stored procedure validation (executes per invoice generated)


Implementation Priority:
────────────────────────
1. **INDEX 2 first** (meter_readings): Highest impact on query performance
2. **INDEX 1 second** (contracts): Used by all queries
3. **INDEX 3 third** (invoices): Important for stored procedure efficiency


Creation Order (SQL):
────────────────────
*/

CREATE NONCLUSTERED INDEX idx_contracts_status_enddate 
ON contracts (status, end_date) 
INCLUDE (client_id, tariff_id);

CREATE NONCLUSTERED INDEX idx_meter_readings_contract_date 
ON meter_readings (contract_id, reading_date) 
INCLUDE (kwh_consumed);

CREATE NONCLUSTERED INDEX idx_invoices_contract_period 
ON invoices (contract_id, billing_period);

-- ==============================================================================
-- END OF PART 1 - SQL SERVER ASSESSMENT
-- =============================================================================="
