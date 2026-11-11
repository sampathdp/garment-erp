<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title')</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 24px; font-size: 12px; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    .meta { color: #555; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; vertical-align: top; }
    th { background: #eee; }
    .grid { display: flex; gap: 24px; }
    .grid > div { flex: 1; }
    img.design { max-width: 100%; max-height: 360px; border: 1px solid #ccc; }
    .footer { margin-top: 24px; color: #555; font-size: 10px; }
    @page { margin: 12mm; }
</style>
</head>
<body>
@yield('content')
<div class="footer">Printed by {{ $printedBy }} on {{ $printedAt->timezone('Asia/Colombo')->format('Y-m-d H:i') }} (Asia/Colombo)</div>
</body>
</html>
