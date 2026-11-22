Registers internal opportunity and activity storage. Enabling it copies
no host data and transfers no CRM authority. Entity access is denied by
default. Access-checked entity queries return no rows while stored
records exist. There are no routes, forms, Views or menu links.

Default contact-policy, records and contact-match ports stay unavailable
so legacy remains the only writer. Missing and foreign records share
not_found without an entity payload. A claim never creates an
opportunity. ContactEmail, OperatingSchedule and OperatingDraft are
value objects and are not runtime services. Activity is revisionable
(accepted vault D4) even though the ontology draft marks it not
revisionable. Opportunity amount is stored when supplied and is never
given a default.

InstructionReleasePolicy, PipelineStagePolicy, IntakeDraftPolicy and
InquiryReviewPolicy are storage-free refuse/retry contracts for the
remaining S3 CRM kernel tests (InstructionReleaseTest, PipelineTest,
IntakeDraftTest, InquiryReviewTest). HTTP, Webform, Jira, Views and
Gin stay host.
