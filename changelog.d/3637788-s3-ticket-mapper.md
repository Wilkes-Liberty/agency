---
section: Added
---
Add a ticket submission mapper that copies allowlisted public fields
and caps priority at Medium. Unexpected private fields are dropped.
JSM option ids and customfield keys stay host. Drupal.org issue
3637788.
The summary is single-line text capped at 200 characters. Non-text
summary and email values are ignored.
