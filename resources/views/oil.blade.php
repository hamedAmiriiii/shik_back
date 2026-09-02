<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0c1218">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>تعویض روغن</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('oil/app.css') }}?v={{ filemtime(public_path('oil/app.css')) }}">
</head>
<body>
    <div id="app"></div>
    <script>
        window.OIL = {
            api: @json(url('/api/oil')),
            letters: @json(\App\Tools\PlateTools::LETTERS),
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <script src="{{ asset('oil/app.js') }}?v={{ filemtime(public_path('oil/app.js')) }}"></script>
</body>
</html>
