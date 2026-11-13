<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Elevate Pesa</title>
    <style>
        :root { --brand: #10b981; --brand-dark: #047857; --bg: #f3f6f4; --card: #ffffff; --text: #111827; --muted: #6b7280; --border: #e5e7eb; --error: #b91c1c; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b1210; --card: #111b18; --text: #e5e7eb; --muted: #9ca3af; --border: #1f2d29; --error: #f87171; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .card { width: 100%; max-width: 400px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 32px 28px; box-shadow: 0 10px 30px rgba(0,0,0,.06); }
        .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 24px; font-weight: 700; color: var(--brand); font-size: 18px; }
        .brand span.mark { width: 36px; height: 36px; border-radius: 10px; background: var(--brand); color: #fff; display: grid; place-items: center; font-size: 20px; }
        h1 { font-size: 22px; margin: 0 0 6px; }
        p { color: var(--muted); margin: 0 0 20px; line-height: 1.5; }
        label { display: block; font-size: 14px; font-weight: 600; margin: 0 0 6px; }
        input[type=password] { width: 100%; padding: 11px 12px; border: 1px solid var(--border); border-radius: 10px; font-size: 15px; background: transparent; color: var(--text); margin-bottom: 16px; }
        input[type=password]:focus { outline: 2px solid var(--brand); outline-offset: 1px; border-color: transparent; }
        button { width: 100%; padding: 12px; border: 0; border-radius: 10px; background: var(--brand); color: #fff; font-size: 15px; font-weight: 600; cursor: pointer; }
        button:hover { background: var(--brand-dark); }
        .error { color: var(--error); font-size: 14px; margin: -8px 0 14px; }
        .ok { width: 52px; height: 52px; border-radius: 50%; background: rgba(16,185,129,.12); color: var(--brand); display: grid; place-items: center; font-size: 28px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand"><span class="mark">e</span>Elevate Pesa</div>
        @yield('content')
    </main>
</body>
</html>
