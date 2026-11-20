---
section: Fixed
---
Report a missing agency name with `RequirementSeverity` on Drupal 11.2+ and
severity `2` on Drupal 10.6, avoiding the Drupal 12-removed
`REQUIREMENT_ERROR` constant. Associative database fetches keep a Drupal 10
PDO fallback on the same line as `FetchAs` so Upgrade Status does not treat
it as a Drupal 12 fetch-mode use.
