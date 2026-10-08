<?php

namespace App\Services\Ai\Content;

use App\Services\Content\ContentHtmlSanitizer;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Làm sạch HTML của nguồn, AI output và editor theo một allowlist.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: sanitize().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTML nguồn/model/editor không tin cậy.
 * - OUTPUT: semantic HTML, không script/event, giữ inline media asset refs.
 * =====================================================================
 */
final class AiContentSanitizer extends ContentHtmlSanitizer {}
