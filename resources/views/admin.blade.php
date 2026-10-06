{{--
  =====================================================================
  CHỨC NĂNG FILE: Khung SPA quản trị và branding bootstrap từ Settings.
  CÁC HÀM/METHOD TRONG FILE: Không có; render Blade và khởi tạo màu loader.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : branding DTO public từ view composer.
  - OUTPUT: favicon/logo loader và JSON bootstrap; không nhận HTML upload.
  =====================================================================
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="UTF-8" />
  <link rel="icon" href="{{ $branding['favicon_url'] }}" type="{{ $branding['favicon_type'] }}" />
  <meta name="robots" content="noindex, nofollow" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>@yield('title', config('app.name'))</title>
  <link rel="stylesheet" type="text/css" href="{{ asset('loader.css') }}" />
  @php($brandingBootstrap = \Illuminate\Support\Arr::only($branding, ['logo_url', 'favicon_url', 'favicon_type']))
  <script type="application/json" id="site-branding">@json($brandingBootstrap)</script>
  @vite(['resources/js/main.js'])
</head>

<body>
  <div id="app">
    <div id="loading-bg">
      <div class="loading-logo">
        @if ($branding['logo_url'])
          <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['site_name'] }}" style="max-width:160px;height:48px;object-fit:contain" />
        @else
        <!-- SVG Logo -->
        <svg width="86" height="48" viewBox="0 0 34 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path fill-rule="evenodd" clip-rule="evenodd"
            d="M0.00183571 0.3125V7.59485C0.00183571 7.59485 -0.141502 9.88783 2.10473 11.8288L14.5469 23.6837L21.0172 23.6005L19.9794 10.8126L17.5261 7.93369L9.81536 0.3125H0.00183571Z"
            fill="var(--initial-loader-color)"
/>
          <path opacity="0.06" fill-rule="evenodd" clip-rule="evenodd"
            d="M8.17969 17.7762L13.3027 3.75173L17.589 8.02192L8.17969 17.7762Z" fill="#161616"
/>
          <path opacity="0.06" fill-rule="evenodd" clip-rule="evenodd"
            d="M8.58203 17.2248L14.8129 5.24231L17.6211 8.05247L8.58203 17.2248Z" fill="#161616"
/>
          <path fill-rule="evenodd" clip-rule="evenodd"
            d="M8.25781 17.6914L25.1339 0.3125H33.9991V7.62657C33.9991 7.62657 33.8144 10.0645 32.5743 11.3686L21.0179 23.6875H14.5487L8.25781 17.6914Z"
            fill="var(--initial-loader-color)"
/>
        </svg>
        @endif
      </div>
      <div class=" loading">
        <div class="effect-1 effects"></div>
        <div class="effect-2 effects"></div>
        <div class="effect-3 effects"></div>
      </div>
    </div>
  </div>

  <script>
    const loaderColor = localStorage.getItem('vuexy-initial-loader-bg') || '#FFFFFF'
    const primaryColor = localStorage.getItem('vuexy-initial-loader-color') || '#7367F0'

    if (loaderColor)
      document.documentElement.style.setProperty('--initial-loader-bg', loaderColor)
    if (loaderColor)
      document.documentElement.style.setProperty('--initial-loader-bg', loaderColor)

    if (primaryColor)
      document.documentElement.style.setProperty('--initial-loader-color', primaryColor)
    </script>
  </body>
</html>
