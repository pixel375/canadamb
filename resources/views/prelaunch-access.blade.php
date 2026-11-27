<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>CanadaMB · Prelaunch access</title>
    <style>
        :root{color-scheme:light dark;font-family:system-ui,sans-serif}body{margin:0;min-height:100svh;display:grid;place-items:center;background:#101827;color:#eef2f7}main{box-sizing:border-box;width:min(92%,420px);padding:32px;border:1px solid #334155;border-radius:18px;background:#192436}h1{font-size:1.65rem;margin:12px 0}p{line-height:1.5;color:#b8c7d9}label{display:block;margin:24px 0 8px;font-weight:600}input,button{box-sizing:border-box;width:100%;padding:13px;border-radius:8px;font:inherit}input{background:#101827;color:#fff;border:1px solid #64748b}button{margin-top:16px;background:#e2e8f0;color:#142034;border:0;font-weight:700;cursor:pointer}small{letter-spacing:.12em;color:#b8c7d9}.error{color:#fda4af}input:focus-visible,button:focus-visible{outline:3px solid #93c5fd;outline-offset:2px}
    </style>
</head>
<body>
<main>
    <small>CANADAMB</small>
    <h1>Prelaunch access</h1>
    <p>This website is currently being prepared for launch. Enter the preview password to continue.</p>
    @if($message)<p class="error" role="alert">{{ $message }}</p>@endif
    <form method="post" action="/prelaunch-access">
        <input type="hidden" name="return_to" value="{{ $target }}">
        <label for="preview-password">Preview password</label>
        <input id="preview-password" name="preview_password" type="password" required maxlength="72" autocomplete="current-password" autofocus>
        <button type="submit">Continue</button>
    </form>
</main>
</body>
</html>
