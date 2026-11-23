---
section: Added
---
Add a deny-closed private-search hatch. Lookup and filter require a
tenant claim, then share not_found without query text or client
account numbers. Typesense, Solr and live indexes stay host.
Drupal.org issue 3637789.
Query text and filter criteria are marked sensitive so they stay out
of exception backtraces.
