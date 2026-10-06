{{--
  =====================================================================
  CHỨC NĂNG FILE: Layout public dùng thông tin website và branding đã lưu.
  CÁC HÀM/METHOD TRONG FILE: Không có; render Blade.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : site/seo và branding DTO từ composer.
  - OUTPUT: favicon và logo header; dữ liệu được escape bằng Blade.
  =====================================================================
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="UTF-8" />
  <link rel="icon" href="{{ $branding['favicon_url'] }}" type="{{ $branding['favicon_type'] }}" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />

  <title>@yield('title', $site['site_name'] ?? config('app.name'))</title>
  <meta name="description" content="@yield('meta_description', $seo['default_description'] ?? '')" />

  @stack('meta')
  @vite(['resources/css/public.css'])
  @stack('styles')
</head>

<body>
  <a class="skip-link" href="#main-content">{{ __('Bỏ qua tới nội dung chính') }}</a>

  <header class="site-header">
    <div class="container site-header__inner">
      <a class="site-brand" href="{{ route('home') }}">
        @if ($branding['logo_url'])
          <img src="{{ $branding['logo_url'] }}" alt="" style="max-width:160px;height:36px;object-fit:contain;vertical-align:middle;margin-inline-end:8px" />
        @endif
        {{ $site['site_name'] ?? config('app.name') }}
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
      <p>&copy; {{ now($site['timezone'] ?? config('app.timezone'))->year }} {{ $site['site_name'] ?? config('app.name') }}</p>
      @if (!empty($site['contact_email']))
        <a href="mailto:{{ $site['contact_email'] }}">{{ $site['contact_email'] }}</a>
      @endif
    </div>
  </footer>
</body>
</html>
