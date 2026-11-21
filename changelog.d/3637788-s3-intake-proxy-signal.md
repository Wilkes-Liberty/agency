---
section: Added
---
Port a generic intake proxy-signal verifier into agency_intake. Only a
fresh HMAC bound to the exact request body can add suspicion. Empty
secrets and stale timestamps never trust. Drupal.org issue 3637788.
