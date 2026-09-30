/**
 * =====================================================================
 * CHỨC NĂNG FILE: Runtime TinyMCE self-host tải lười sau lựa chọn GPL rõ ràng.
 * CÁC HÀM/METHOD TRONG FILE: Không có; đăng ký theme/model/plugin và CSS.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): dynamic import -> TinyMCE global và bundledStyles.
 * Không tải plugin/skin từ CDN; chỉ dùng plugin cộng đồng cần cho Post.
 * =====================================================================
 */
import 'tinymce/tinymce'
import 'tinymce/icons/default'
import 'tinymce/themes/silver'
import 'tinymce/models/dom'
import 'tinymce/plugins/lists'
import 'tinymce/plugins/link'
import 'tinymce/plugins/image'
import 'tinymce/plugins/table'
import 'tinymce/plugins/code'
import 'tinymce/plugins/wordcount'
import 'tinymce/skins/ui/oxide/skin.min.css'
import uiContent from 'tinymce/skins/ui/oxide/content.min.css?raw'
import content from 'tinymce/skins/content/default/content.min.css?raw'

export const bundledStyles = `${uiContent}\n${content}`
