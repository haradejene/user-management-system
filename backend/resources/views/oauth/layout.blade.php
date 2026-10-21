<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title') · Doxa</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f4f7fa;color:#142538;font:16px/1.6 system-ui,-apple-system,sans-serif}main{min-height:100vh;display:grid;place-items:center;padding:32px 20px}.card{width:100%;max-width:560px;background:white;border:1px solid #dde5ed;border-radius:24px;padding:40px;box-shadow:0 12px 40px #14253808}.brand{display:flex;align-items:center;gap:10px;font-weight:750;letter-spacing:-.04em;font-size:24px}.mark{display:grid;place-items:center;background:#142538;color:white;border-radius:12px;width:40px;height:40px;font-size:22px}h1{font-size:30px;line-height:1.2;letter-spacing:-.035em;margin:32px 0 12px}h2{font-size:16px;margin:24px 0 12px}.muted{color:#526477}.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.12em;font-weight:650;color:#527079}.account{display:flex;gap:14px;align-items:center;text-align:left;padding:16px;border:1px solid #dce5ec;border-radius:14px;background:#f8fafc;min-width:0}.avatar{flex-shrink:0;display:grid;place-items:center;width:44px;height:44px;border-radius:50%;background:#d9eee9;color:#18574d;font-weight:700}.identity{min-width:0}.email{overflow-wrap:anywhere;font-size:14px;color:#526477}button{cursor:pointer;font:inherit;min-height:48px;border-radius:12px;padding:12px 20px;font-weight:650;border:1px solid transparent}.primary{background:#142538;color:white}.primary:hover{background:#29435a}.secondary{background:white;color:#253f55;border-color:#ccd9e3}.secondary:hover{background:#f0f5f8}button:focus-visible,a:focus-visible{outline:3px solid #008575;outline-offset:4px}.full{width:100%;margin-top:12px}.actions{display:flex;gap:12px;justify-content:flex-end;margin-top:28px}.actions form{flex:1}.actions button{width:100%}.permissions{padding:0;list-style:none}.permissions li{padding:14px 0;border-bottom:1px solid #e7edf2;display:flex;gap:12px}.check{color:#157464;font-weight:700}.notice{background:#f0f7f5;border:1px solid #d5e8e2;border-radius:12px;padding:14px;font-size:14px;color:#34584f}.footer{margin-top:28px;padding-top:18px;border-top:1px solid #e7edf2;font-size:12px;color:#657787}.text-button{background:none;color:#526477;width:100%;margin-top:8px}p{margin:12px 0}strong{overflow-wrap:anywhere}@media(max-width:480px){main{padding:16px}.card{padding:28px 22px;border-radius:18px}h1{font-size:26px}}
    </style>
</head>
<body><main><section class="card" aria-labelledby="page-title">
    <div class="brand"><span class="mark" aria-hidden="true">d</span> doxa<span class="eyebrow" style="margin-left:auto">One identity</span></div>
    @yield('content')
    <footer class="footer">Doxa Identity · Secure access to your workplace</footer>
</section></main></body>
</html>
