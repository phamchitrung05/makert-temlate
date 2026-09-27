<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="UTF-8" />
  <link rel="icon" href="{{ asset('favicon.ico') }}" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />

  <title>@yield('title', config('app.name'))</title>
  <meta name="description" content="@yield('meta_description', config('app.name'))" />

  @stack('meta')
  @vite(['resources/css/public.css'])
  @stack('styles')
</head>

<body>
  <a class="skip-link" href="#main-content">{{ __('Bỏ qua tới nội dung chính') }}</a>

  <header class="site-header">
    <div class="container site-header__inner">
      <a class="site-brand" href="{{ route('home') }}">
        {{ config('app.name') }}
      </a>

      <nav class="site-nav" aria-label="{{ __('Điều hướng chính') }}">
        <a href="{{ route('home') }}">{{ __('Trang chủ') }}</a>
        <a href="{{ url('/resources') }}">{{ __('Tài nguyên') }}</a>
        <a href="{{ url('/blog') }}">{{ __('Blog') }}</a>
        <a href="{{ url('/pricing') }}">{{ __('Bảng giá') }}</a>
        <a href="{{ route('admin.login') }}">{{ __('Quản trị') }}</a>
      </nav>
    </div>
  </header>

  <main id="main-content">
    @yield('content')
  </main>

  <footer class="site-footer">
    <div class="container">
      <p>&copy; {{ date('Y') }} {{ config('app.name') }}</p>
    </div>
  </footer>
</body>
</html>
