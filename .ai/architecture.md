# Architecture Guidelines

Use a simplified layered architecture inspired by Symfony best practices.

Layers:

Controller
↓
Service
↓
Repository
↓
Entity

Responsibilities:

## Controller

- Handle HTTP request
- Validate input
- Call service
- Return HTTP response

Controllers should not contain business logic.

## Service

Contains application business logic.

Examples:

- InvoiceService
- ErseSyncService

## Repository

Handles database access.

Examples:

- ContractRepository
- InvoiceRepository

## Entity

Represents database models.

Examples:

- Contract
- Invoice
- ContractSync

Entities should contain minimal logic.