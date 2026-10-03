<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · CarYard Admin</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-ivory">
    <main class="mx-auto flex min-h-dvh max-w-md flex-col justify-center gap-5 px-5 py-10">
        <div class="font-display text-[24px] font-bold text-forest">Car<span class="text-clay">Yard</span> <span class="font-sans text-[12px] font-semibold text-muted">ADMIN</span></div>
        <div class="card flex flex-col gap-4 p-6">@yield('content')</div>
    </main>
</body>
</html>
