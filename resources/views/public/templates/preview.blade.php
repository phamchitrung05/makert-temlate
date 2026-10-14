<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
    <title>{{ $title }} · {{ $version }} · Preview</title>
    <style>body{margin:0;font:16px system-ui;background:#f5f5f7;color:#202233}header{padding:12px 20px;background:white}main{display:flex;min-height:88vh}nav{width:220px;padding:16px;flex-shrink:0}nav a{display:block;padding:8px;color:#4540a0}section{flex:1}iframe{width:100%;height:90vh;border:0;background:white}summary{cursor:pointer}img{max-width:100%}@media(max-width:650px){main{display:block}nav{width:auto}}</style>
</head>
<body>
<header><strong>{{ $title }}</strong> · v{{ $version }}
    <details><summary>Thông tin hiển thị / fallback</summary>
        <p>Preview chạy trong vùng cách ly. CDN bên ngoài có thể bị chặn; giao diện demo không cung cấp backend thật.</p>
        @foreach($warnings as $warning)<p>{{ $warning }}</p>@endforeach
        @if($screenshot)<img src="{{ $screenshot }}" alt="Ảnh chụp màn hình dự phòng">@else<p>Chưa có screenshot hợp lệ.</p>@endif
    </details>
</header>
<main><nav aria-label="Danh sách màn hình">
    @foreach($screens as $screen)<a href="{{ $prefix }}?screen={{ rawurlencode($screen['slug']) }}">{{ $screen['title'] }}</a>@endforeach
</nav><section><iframe src="{{ $src }}" title="Live template preview" sandbox="allow-scripts" referrerpolicy="no-referrer"></iframe></section></main>
</body></html>
