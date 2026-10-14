# TPL-08 — Preview origin/static file server

**Trạng thái:** PASS · 15/10/2026

Host/file routes chạy stateless với grant hash, checksum/build recheck, manifest file allowlist, extension MIME allowlist và private no-store. Traversal, encoded path, backslash, symlink/outside root bị chặn; CSP sandbox chỉ cho scripts cần thiết, không bật same-origin/forms/popups.

**Bằng chứng:** TemplatePreviewServiceTest — 2 tests / 6 assertions; TemplatePackageExtractorTest và ResourceTemplateProcessingTest kiểm fixture normalized 18 screen/raw 9 screen, relative asset và cleanup.
