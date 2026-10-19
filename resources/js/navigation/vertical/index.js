/**
 * =====================================================================
 * CHỨC NĂNG FILE: Ghép các nhóm menu dọc có liên kết sử dụng được.
 * CÁC HÀM/METHOD TRONG FILE: không có; export mảng menu.
 * INPUT/OUTPUT CỦA FILE (tổng thể): các nhóm menu -> navigation layout dọc.
 * =====================================================================
 */
import appsAndPages from './apps-and-pages'
import charts from './charts'
import dashboard from './dashboard'
import forms from './forms'
import uiElements from './ui-elements'

export default [...dashboard, ...appsAndPages, ...uiElements, ...forms, ...charts]
