-- table for exercise 3
CREATE TABLE contract_syncs (
    id INT IDENTITY PRIMARY KEY,
    contract_id INT NOT NULL,
    erse_external_id VARCHAR(50) NULL,
    status VARCHAR(20) NOT NULL,
    response_payload TEXT NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
