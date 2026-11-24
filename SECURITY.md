# Security Policy

BiscuitWP handles consent records for site visitors, so we take security reports seriously. Thank you for reporting responsibly.

## Reporting a vulnerability

Please do not open a public issue for a security problem. Use one of two private channels:

1. **GitHub private report (preferred):** <https://github.com/rtdesignstudio/biscuitwp/security/advisories/new>
2. **Email:** [security@rt.md](mailto:security@rt.md)

Include the plugin version, WordPress and PHP versions, what you found, how to reproduce it, and the impact you expect. A proof of concept helps. Please do not access data that is not yours and do not test on sites you do not own.

## What to expect

- **Acknowledgement within 72 hours** of your report.
- **A fix within 30 days** for confirmed vulnerabilities. If it needs more time, we will tell you why and agree a date with you.
- **Disclosure after the fix is released**, or after 90 days at the latest, unless we agree otherwise. We are happy to credit you if you wish.

## Supported versions

Security fixes ship in the **newest release**, which sites receive as an automatic update. For the previous minor version (for example 1.0.x when the latest is 1.1.x) we publish the fix only on GitHub Releases, as a signed zip, without an automatic update. Please update to the newest release before reporting.

## Updates and verification

Releases are signed (Ed25519) and delivered from <https://biscuitwp.rt.md/> through a signed manifest. The public keys and how to verify a download by hand are on the security page: <https://biscuitwp.rt.md/en/security/> (Romanian: <https://biscuitwp.rt.md/securitate/>).
