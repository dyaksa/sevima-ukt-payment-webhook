# Decisions

## Recorded during implementation

Smaller rulings the candidate made while a ticket was built.

### Webhook log is exempt from the institution scope

**Context.** Every domain model uses the `BelongsToInstitution` trait, which stamps and filters rows by the current institution and throws when none is known. `tests/Unit/ArchitectureTest.php` enforces this. The webhook log must also record notifications that belong to no institution: an unknown `X-Institution-Code`, or a request rejected before its institution is trusted.

**Options.**
1. Scope the webhook log like every other model. Rejected requests with an unknown code could not be logged.
2. Exempt the webhook log from the trait and set `institution_id` explicitly, empty for an unknown code.

**Decision.** Option 2. `App\Models\WebhookLog` is listed as an exception in the architecture test. The sent code is still stored in `institution_code`.

**Consequences.** Every notification is audited, rejected ones included. The application only writes webhook log rows and never reads them, so the missing scope cannot leak one institution's data to another. A future read path (an audit endpoint, say) must filter by institution explicitly.

### A ledger amount is empty for events that carry no money

**Context.** Amounts are integer rupiah, BIGINT with `CHECK (amount > 0)`. Some ledger events carry no amount, such as the gateway's expiry event.

**Options.**
1. Make `transactions.amount` required and store a stand-in (the invoice amount) for events without one.
2. Make `transactions.amount` nullable and keep the check for every amount that is present.

**Decision.** Option 2. PostgreSQL passes the check for NULL, so a present amount is still a positive integer.

**Consequences.** The ledger holds only amounts the gateway actually sent. The status calculator must treat an empty amount as "no money moved", which it already does by matching only payment rows by amount.
