{{--
  =====================================================================
  CHỨC NĂNG FILE: Trang đăng nhập customer và thông báo lỗi OAuth an toàn.
  CÁC HÀM/METHOD TRONG FILE: Không có; render Blade.
  INPUT/OUTPUT CỦA CLASS (tổng thể): flash error -> HTML escape và liên kết OAuth cố định.
  =====================================================================
--}}
@extends('layouts.public')

@section('title', 'Đăng nhập')

@section('content')
  <section class="container">
    <h1>Đăng nhập</h1>
    @if (session('error'))
      <p role="alert">{{ session('error') }}</p>
    @endif
    <p>Chọn phương thức bạn đã dùng để tạo tài khoản.</p>
    <p><a href="{{ url('/auth/google/redirect') }}">Đăng nhập bằng Google</a></p>
    <p><a href="{{ url('/auth/facebook/redirect') }}">Đăng nhập bằng Facebook</a></p>
  </section>
@endsection
