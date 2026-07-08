<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'SaraPhone') - {{ config('app.name') }}</title>
    @include('layouts.partials.favicon')
    @vite(['resources/css/app.css'])
</head>

<body class="ghl-saraphone-body">
    @yield('content')
    @include('layouts.partials.toasts')
    @stack('scripts')
</body>

</html>
