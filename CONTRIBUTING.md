# Contributing

Run `composer check` and `composer audit` before submitting a change. Add a failing framework-independent test before fixing parser behavior. Keep production PHP compatible with 8.2 and include exact-byte assertions for attachment changes.

Use synthetic fixtures, or public fixtures whose licenses permit redistribution. Document their origin and preserve license notices. Never commit customer emails, personal data, tokens or GPL-derived code to this MIT project. The original fixture generator uses only Python's standard library and should reproduce the checked-in synthetic files byte for byte.

This package owns parsing and normalization. Application storage, HTML sanitation, rendering, OCR, workflow integration and transport belong in consumers.
