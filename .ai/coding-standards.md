# Coding Standards

Follow clean code and SOLID principles.

## Security

Always use parameterized queries.

Never concatenate SQL strings with variables.

Bad:

$pdo->query("SELECT * FROM contracts WHERE id = $id");

Good:

$stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = :id");

## Error Handling

Never use echo for errors.

Use:

- Exceptions
- Proper return values
- Logging

## Dependency Injection

Do not pass raw database objects around.

Use constructor injection.

Example:

class InvoiceService
{
    public function __construct(
        ContractRepository $contracts,
        TariffCalculatorFactory $tariffs
    ) {}
}

## Separation of Concerns

Avoid mixing:

- DB access
- Business logic
- HTTP logic

## Naming

Classes:

PascalCase

Methods:

camelCase

Variables:

camelCase