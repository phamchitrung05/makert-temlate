@extends('layouts.public')

@section('title', $site['site_name'])
@section('meta_description', $seo['default_description'] ?: $site['site_description'])

@push('meta')
  <link rel="canonical" href="{{ rtrim($site['site_url'], '/') }}/" />
@endpush

@section('content')
  <section class="container">
    <h1>{{ $site['site_name'] }}</h1>

    <p>
      Trang chủ public đã được tách khỏi admin dashboard. Nội dung catalog, blog và
      landing page sẽ được render tại đây bằng Blade để tối ưu SEO.
    </p>

    <p>
      <a href="{{ route('admin.login') }}">Đăng nhập quản trị</a>
    </p>
  </section>
@endsection
