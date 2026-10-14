# TPL-10 — Download và share

**Trạng thái:** PASS · 15/10/2026

Policy mặc định: customer active + email verified, quota 20/ngày, URL download 5 phút. Downloads dùng bảng hiện có, request key/idempotency và counter chỉ tăng một lần khi started -> completed. Share lưu token hash, capability preview hoặc download, expiry/revoke; preview share không cấp download.

**Bằng chứng:** ResourceTemplatePublicAccessTest — 3 tests / 18 assertions; kiểm stream retry, counter, sitemap và revoke immediate.
