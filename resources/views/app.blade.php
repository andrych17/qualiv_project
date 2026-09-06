<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $page['props']['theme'] ?? 'classic-navy' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Nusaevo ERP') }}</title>

        <link rel="icon" type="image/x-icon" href="/favicon.ico" />
        <link rel="icon" type="image/png" href="/logos/SysConfig1.png" />
        <link rel="apple-touch-icon" href="/logos/SysConfig1.png" />

        <!-- Fonts — DESIGN.md: Source Serif 4 (display), Inter (UI), IBM Plex Mono (data) -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=source-serif-4:400,600,700|inter:400,500,600,700|ibm-plex-mono:400,500&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased text-ink-900 bg-surface-50" data-theme="{{ $page['props']['theme'] ?? 'classic-navy' }}">
        @inertia
    </body>
</html>
