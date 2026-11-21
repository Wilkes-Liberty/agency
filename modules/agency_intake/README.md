Registers internal intake-submission storage. Enabling it copies no host
data and transfers no intake authority. Entity access is denied by
default. Access-checked entity queries return no rows while stored
records exist. There are no routes, forms, Views or menu links.

Inquiry validates generic intent and platform choices. Constants are
the source of truth; an optional catalog may add product names and
never replaces the generic choices. Overlay catalogs, FormProvenance
and vendor option ids stay host. BusinessSubmissionMapper copies
inquiry and account fields from submitted values, drops private
tokens and résumé values, and refuses unbound staff or hiring form
ids. Inquiry and BusinessSubmissionMapper are not runtime services.
They do not enqueue, create tickets or grant access.

Vendor transport stays a host adapter. Default dispatch, outbox,
policy, queued-delivery and governed-tools ports stay unavailable
so legacy remains the only writer. Missing tenant claims use
missing_tenant_claim. Missing and foreign intents share not_found
without a payload. Pending occupancy reuses UncertainDelivery.
Caller-facing replay of a consumed key is not_found. Generic form
mechanics, mail copy, authored locale and notification language
stay with the host. Payload JSON is stored as a long string.
