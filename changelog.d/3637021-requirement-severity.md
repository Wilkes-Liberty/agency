---
section: Fixed
---
Report a missing agency name with `RequirementSeverity` only. The
`REQUIREMENT_ERROR` fallback is removed in Drupal 12 and unused on the
supported 10.6 floor. Associative database fetches keep a Drupal 10 PDO
fallback on the same line as `FetchAs` so Upgrade Status does not treat
it as a Drupal 12 fetch-mode use.
