# The password reset link uses http

**Status: Open (small).** Found 18 Nov 2026 in `lib/repository/urls.dart` (`josh-feature`, 2e71280).

```dart
const String passwordReset = "http://adb.elevatepesa.com/reset-password";
```

It works today only because the server redirects http to https. Use `https://adb.elevatepesa.com/reset-password`.

Since 15 Nov that page, opened without a code, asks for the account's email and sends a reset link to any active
account, customers included (it used to show "missing its reset code" and send nothing). The emailed link opens the
same page with `?token=...`. Keep the address and the `token` parameter as they are.
