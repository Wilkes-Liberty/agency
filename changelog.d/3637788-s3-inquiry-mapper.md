---
section: Added
---
Add deny-closed inquiry validation and business submission mapping.
Generic intent and platform choices fail closed without a required
product catalog. The mapper drops private fields and refuses staff
and hiring form ids. Overlay catalogs stay host. Drupal.org issue
3637788.
Non-text message, email, subject and organization values are refused
or ignored instead of being cast to text.
