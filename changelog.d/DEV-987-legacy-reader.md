---
section: Added
---
Host adapters load Agency-side rows through an authority-gated reader.
At legacy, shadow or removed, or when a domain is unregistered, the load
is refused with the recorded state as the reason and a minimized outbox
intent. Entity API loads remain ungated.
