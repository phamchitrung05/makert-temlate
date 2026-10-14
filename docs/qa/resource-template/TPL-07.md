# TPL-07 — Admin Vue Template panel

**Trạng thái:** PASS · 15/10/2026

Đã thêm resourceTemplate service, useResourceTemplate polling có request sequence chống stale response, panel processing, screen list/order/entry/title, live preview và screenshot picker. Checkbox is_default đã bỏ khỏi form; promote/rollback đi qua action API riêng.

**Bằng chứng:** tests/frontend/resourceTemplate.test.js — 3 tests; npm run build pass.
