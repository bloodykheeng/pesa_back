# For the mobile app team (Flutter, github.com/MarkRoi/elevate-pesa)

Issues found from the API side that need a change in the app, and what the API guarantees the app. Each issue is a
file named `YYYY-MM-DD-<topic>.md`: what users see, the evidence (server logs, the app code at a commit), the fix
suggested, and how to check it. When you fix one, change its status below and in the file (version, commit).

Who to ask about the API: the backend notes are in this `docs/` folder; the person running the server reads them too.

## Open

| Date | Issue | Status |
| --- | --- | --- |
| 18 Nov 2026 | [Adverts are not loaded after a fresh sign-in](2026-11-18-adverts-not-loaded-after-login.md) | Open |
| 18 Nov 2026 | [The password reset link uses http](2026-11-18-password-reset-link-http.md) | Open (small) |
| 14 Nov 2026 | [Orders show "Package #null" on version 1.0.0](2026-11-14-package-number-null.md) | Fixed in PR #2 (`josh-feature`), not in a store release yet |

## What changed on the API that the app should know

- [2026-11-18 Inactive catalogue records are no longer sent to the app](2026-11-18-catalogue-inactive-records-hidden.md)
  (no app change needed).
- [API rules the app can rely on](api-contract.md): names that will not change, dates, adverts.
