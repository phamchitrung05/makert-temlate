# TPL-05 — Ready/promote/rollback/archive

**Trạng thái:** PASS · 15/10/2026

- ResourceVersionReadyGate kiểm package archive/private/clean, checksum, processing ready, entry/screen và artifact file.
- Ready, promote, rollback và archive chạy dưới transaction/lock Resource; default chỉ đổi qua promote/rollback và download history giữ version.
- Cache invalidation và activity events template.processed, version.ready, version.promoted, version.rolled_back, version.archived đã nối.

**Bằng chứng:** ResourceTemplateLifecycleTest — 4 tests / 32 assertions; regression scoped lifecycle + processing + public access pass.
