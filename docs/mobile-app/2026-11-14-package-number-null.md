# Orders show "Package #null" on version 1.0.0

**Status: Fixed in PR #2 (`josh-feature`), not in a store release yet** (as far as the API side knows, 18 Nov 2026).

Seen during the 14 Nov 2026 review of the app against the new admin: version 1.0.0 shows "Package #null" where a
package's number should be. The API sends `package_number` (since 14 Nov new ones read `PKG` + month and year + a
count, `PKG1120263`; older ones keep their random numbers, and a few very old packages have none, so the field can be
null). PR #2 on github.com/MarkRoi/elevate-pesa changes the display.

To close: release a version with PR #2 and show a dash, or nothing, when `package_number` is null.
