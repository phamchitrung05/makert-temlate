# Sửa sync model APIKEY.FUN — 2026-10-02

## Lỗi và nguyên nhân

- AI Settings có provider `APIKEY.FUN`, driver `openai-compatible`, API base
  `https://api.apikey.fan/v1`, discovery mode `models_endpoint`.
- Sync qua UI trả `AI_PROVIDER_TIMEOUT`; kiểm tra transport gốc thấy cURL error 7
  (không kết nối được), trong khi PHP HTTP request không pin DNS trả HTTP 200.
- Host trả cả IPv4 và IPv6. Client tạo nhiều rule `CURLOPT_RESOLVE` cho cùng
  host/port, khiến rule IPv6 cuối ghi đè các rule IPv4. Máy local không có kết nối
  IPv6 tới host này.

## Thay đổi

- `AiProviderClient` gom toàn bộ IP public đã kiểm tra vào một rule
  `HOST:443:IPv4,IPv4,[IPv6],[IPv6]`, theo contract libcurl.
- cURL có thể chọn địa chỉ kết nối được trong danh sách. Kiểm tra private IP,
  HTTPS, không redirect và key server-side vẫn được áp dụng.
- Tham khảo: [CURLOPT_RESOLVE](https://curl.se/libcurl/c/CURLOPT_RESOLVE.html).

## Kiểm chứng

- Client backend thật trả 4 model ID với key đã lưu trong provider.
- UI Sync models báo `Đã đồng bộ 4 model`, catalog có 4/4 model.
- Test connection qua UI thành công; provider chuyển sang `Healthy`.
- `AiProviderAdapterTest`, `AiImageProviderTest`, `AiProviderSettingsApiTest`:
  22 tests / 99 assertions đạt, HTTP fake và database cô lập.
- Pint file thay đổi và `git diff --check` đạt.
- Ảnh kết quả: `AI_PROVIDER_SYNC_FIX_2026-10-02.png`.

Không gọi API tạo nội dung/ảnh trong lần kiểm tra này. Catalog chỉ chứng minh
model được liệt kê; capability và quyền tạo ảnh cần được xác định riêng.
