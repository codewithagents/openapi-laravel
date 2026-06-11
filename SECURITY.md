# Security Policy

## Threat model

This generator reads an OpenAPI document and writes PHP that your application then loads and
executes, so it treats the spec as **untrusted input**. The hardening that follows from that:

- docblock-injection neutralization (spec text cannot break out of generated docblocks)
- identifier whitelisting (namespace and class-name options are validated before any file is written)
- structural rejection of non-OpenAPI documents
- a pre-parse input-size guard that caps the YAML alias-bomb blast radius
- a hostile-input regression test suite guarding all of the above

Output paths are written exactly where you point them by design. Point them at fixed,
operator-controlled locations and never derive them from untrusted input.

## Supported versions

| Version | Supported |
|---------|-----------|
| Latest release (the newest minor of the newest major) | Yes |
| Older releases | No, please upgrade |

## Reporting a vulnerability

Please **do not open a public issue** for security problems. Report privately via GitHub
Security Advisories:

https://github.com/codewithagents/openapi-laravel/security/advisories/new

You will get an acknowledgement, and a fix or a coordinated disclosure plan as quickly as the
issue warrants.
