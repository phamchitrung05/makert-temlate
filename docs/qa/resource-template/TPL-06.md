# TPL-06 — Admin API và policy

**Trạng thái:** PASS · 15/10/2026

Admin API có FormRequest cho process/settings/screenshot, eager-load template, screens và media; process trả 202; response không lộ artifact path/private exception. Permission resource_versions.manage và cross-version screen check nằm ở route/controller/action boundary. Contract chi tiết ở [RESOURCE_TEMPLATE_API.md](../../api/RESOURCE_TEMPLATE_API.md).

**Bằng chứng:** lifecycle/processing feature tests và route list kiểm đủ endpoint; resource response kiểm template state bounded.
