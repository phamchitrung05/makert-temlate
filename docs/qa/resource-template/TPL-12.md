# TPL-12 — Vận hành và tài liệu

**Trạng thái:** PASS · 15/10/2026

Queue job ProcessTemplateVersionJob dùng tries=3, timeout cấu hình và backoff 30/120/300 giây; command resource-templates:diagnose phát hiện stale processing, missing artifact và dọn orphan theo retention. Config/env cho artifact disk, grant/download/share/quota/CSP host đã ghi ở config/resource-templates.php và .env.example.

Backup giữ ZIP gốc, DB manifest/screens/usages/downloads; derived preview có thể rebuild bằng process endpoint. Runbook/contract nằm ở ENVIRONMENT.md, RESOURCE_TEMPLATE_API.md và RESOURCE_TEMPLATE.md.
