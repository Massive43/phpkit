<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>@yield('title') | {{ config('app.name') }}</title>
    <meta name="description" content="@yield('meta_description', __('Website Profile UPT Materia Medica Batu Dinas Kesehatan Pemerintah Provinsi Jawa Timur'))">
    {{-- Keywords for SEO --}}
    <meta name="keywords" content="@yield('meta_keywords', 'laboratorium herbal, tanaman obat, layanan pengujian herbal')">
    {{-- Open Graph Meta Tags for Social Media --}}
    <meta property="og:title" content="@yield('title') | {{ config('app.name') }}">
    <meta property="og:description" content="@yield('meta_description', __('Website Profile UPT Materia Medica Batu Dinas Kesehatan Pemerintah Provinsi Jawa Timur'))">
    <meta property="og:image" content="@yield('meta_image')"> {{-- Replace with your image URL --}}
    <meta property="og:url" content="@yield('meta_url')">
    <meta property="og:type" content="website">
    {{-- Twitter Card Meta Tags --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="@yield('title') | {{ config('app.name') }}">
    <meta name="twitter:description" content="@yield('meta_description', __('Website Profile UPT Materia Medica Batu Dinas Kesehatan Pemerintah Provinsi Jawa Timur'))">
    <meta name="twitter:image" content="@yield('meta_image')">
    {{-- Google Site Verification --}}
    <meta name="google-site-verification" content="q3Hu7DAAQhOAvdzUmotNhMTLeKB1mpmgqwn-2Yax-Sw" />
    {{-- favicon --}}
    <link rel="icon" type="image/x-icon" href="/favicon/favicon.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/favicon/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/favicon/android-chrome-512x512.png">
    <link rel="apple-touch-icon" href="/favicon/apple-touch-icon.png">
    @vite('resources/css/app.css')
    @vite(['resources/js/app.js'])
    @yield('css')
</head>
<body>
    <div class="min-h-screen flex flex-col bg-gray-100">
        <x-public.header />
        <div class="flex-grow">
            @yield('content')
        </div>
        <x-public.social-bar />
        <x-public.application-bar />
        {{-- <x-public.chat-helpdesk /> --}}
        <x-public.footer />
    </div>
    @yield('js')
</body>
</html>
