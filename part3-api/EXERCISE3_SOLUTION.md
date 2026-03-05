# Exercise 3 – API Integration

## 📦 Problem description
FactorEnergia is expanding into Portugal and must register every electricity supply contract with the Portuguese energy regulator (ERSE). The regulator exposes a REST API; our internal system holds contracts in a database. The new module must:

1. Accept a contract ID via an internal endpoint (`POST /api/contracts/sync`).
2. Load the contract data from our database.
3. Transform it to the ERSE API format.
4. Call the external API with proper authentication.
5. Record the outcome of the attempt for auditing and troubleshooting.

Additionally, the request must only succeed for Portuguese contracts and must gracefully handle validation, duplicates, and server errors.


## 🛠 Key components of the solution

- **Data model (`ContractSync`)**: tracks each synchronization attempt with status (`pending`, `success`, `failed`), the ERSE external ID when available, raw response payload and timestamps.
- **Repository (`ContractSyncRepository`)**: simple PDO-backed class to insert/update sync records and query pending operations.
- **Service (`ErseSyncService`)**: the core orchestrator. It:
  - creates an initial pending record,
  - loads and validates the contract,
  - builds the JSON payload,
  - performs the HTTP POST using Symfony HttpClient,
  - interprets HTTP codes (201, 400, 409, others) and updates the sync record appropriately.
  - logs warnings/errors for operational visibility.
- **Controller (`ContractSyncController`)**: exposes the `/api/contracts/sync` endpoint; performs basic request validation and converts service results into proper HTTP responses.

Contracts were extended with additional fields/behaviour to support ERSE data (NIF, CUPS, address, start date, estimated kWh).


## 🧠 Design rationale

- **Separation of concerns**: business logic lives in the service; persistence is abstracted via repositories; HTTP details confined to controller and HttpClient.
- **Dependency injection**: services receive collaborators in constructors, facilitating testing and replacing implementations (e.g. a mock HTTP client).
- **Extensibility and traceability**: the sync entity keeps the full ERSE response which is invaluable for debugging; adding future fields (e.g. retry count) is straightforward.
- **Resilience**: network/transport errors are caught and converted into failed syncs without crashing the application.


## 🧾 Answers to exercise questions

1. **Avoiding concurrent syncs:** acquire a lock or check for an existing pending sync before starting; a database unique constraint on `(contract_id, status)` or a row‑level `SELECT FOR UPDATE` would prevent duplicates. A message queue could also serialize requests.
2. **Handling ERSE outages:** queue the request (store status `pending` and run a background worker/cron to retry) or use a durable message broker; never discard the payload.
3. **Configuration storage:** store the ERSE base URL and bearer token in environment variables accessed via Symfony parameters (`%env(ERSE_URL)%`, `%env(ERSE_TOKEN)%`). This keeps secrets out of source control and allows environment-specific overrides.


## ✅ Summary
The module solves part three of the technical assessment by providing a clean, testable, Symfony‑style implementation that respects project coding standards and anticipates operational concerns. It can be wired into an actual application with minimal effort.
