@extends('layouts.public')

@section('title', config('app.name'))
@section('meta_description', 'Nền tảng bán và phân phối tài nguyên số cho developer và designer.')

@section('content')
  <section class="container">
    <h1>{{ config('app.name') }}</h1>

    <p>
      Trang chủ public đã được tách khỏi admin dashboard. Nội dung catalog, blog và
      landing page sẽ được render tại đây bằng Blade để tối ưu SEO.
    </p>

    <p>
      <a href="{{ route('admin.login') }}">Đăng nhập quản trị</a>
    </p>
  </section>
@endsection
