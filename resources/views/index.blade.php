<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Tracking System')</title>

    @stack('scripts')

    @stack('styles')
</head>
<body style="margin:0;padding:0">
	@yield('content')
</body>
</html>
