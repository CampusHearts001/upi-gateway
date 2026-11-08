# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| Latest  | ✅ Yes     |
| Older   | ❌ No      |

Only the latest commit on the `main` branch receives security fixes.

## Reporting a Vulnerability

Public merchant registration is disabled by default (`ALLOW_REGISTRATION=false`). Only enable it intentionally after deciding who may create merchant accounts; keep the private `.env` file outside the public document root.

**Please do not open a public GitHub issue for security vulnerabilities.**

To report a vulnerability:
- Submit a confidential report via **GitHub Security Advisories** on this repository using the "Report a vulnerability" feature.
- Alternatively, if GitHub private vulnerability reporting is unavailable, reach out to the project maintainers through verified private communication channels.

> [!IMPORTANT]
> **Maintainer Notice for Deployments & Releases:**
> Before deploying or publicly releasing this repository, maintainers must configure a dedicated, monitored security contact address or ensure GitHub Private Vulnerability Reporting is active.

Include:
1. Description of the vulnerability and the component affected.
2. Steps to reproduce (or a proof-of-concept if available).
3. Potential impact (data exposure, account takeover, SSRF, etc.).
4. Suggested fix if you have one.

We aim to respond within **48 hours** and to publish a fix within **7 days** for critical issues.

We do not currently offer a bug bounty but will credit researchers in release notes unless they prefer to remain anonymous.

## Scope

In scope:
- Authentication / authorisation bypass
- SSRF, XSS, CSRF, SQL injection
- Payment data exposure
- API key/secret leakage
- Webhook forgery

Out of scope:
- Issues in local/dev-only configurations (`APP_DEBUG=true`, `APP_ENV=local`)
- Self-XSS or social-engineering attacks
- Denial of service via resource exhaustion without prior authentication
