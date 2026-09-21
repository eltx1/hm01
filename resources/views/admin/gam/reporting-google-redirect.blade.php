<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="referrer" content="no-referrer">
    <meta http-equiv="refresh" content="0;url={{ $authorizationUrl }}">
    <title>Connecting to Google · Horus Media</title>
    <x-brand.favicons />
    @vite(['resources/css/app.css'])
</head>
<body class="auth-page">
    <main class="auth-card hm-panel" id="main-content">
        <p class="eyebrow">Horus Media · Website reporting</p>
        <h1>Connecting to Google</h1>
        <p role="status">Opening Google so you can choose your account and authorize reports.</p>
        <p><a class="hm-button-primary button-link" href="{{ $authorizationUrl }}" rel="noreferrer">Continue to Google</a></p>
        <p><a class="text-link" href="{{ $returnUrl }}">Back to website reports</a></p>
    </main>
</body>
</html>
