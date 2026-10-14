# TPL-11 — Test/QA và fixture report

**Trạng thái:** PASS · 15/10/2026

Fixture normalized/raw và manifest/schema validator đã có từ TPL-00–TPL-04; security archive/path/MIME, queue stale token/idempotency, ready gate, promote/rollback, public policy, share/download và frontend polling đều có test scoped tương ứng. Browser smoke cần chạy thêm trên host có Vite/queue worker thật.

**Lượt scoped cuối:** 13 backend tests / 85 assertions cho lifecycle, processing, preview và public access; frontend Resource Template 3 tests; Vite production build pass.
