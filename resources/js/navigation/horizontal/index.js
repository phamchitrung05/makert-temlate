/**
 * =====================================================================
 * CHỨC NĂNG FILE: Ghép các nhóm menu ngang có liên kết sử dụng được.
 * CÁC HÀM/METHOD TRONG FILE: không có; export mảng menu.
 * INPUT/OUTPUT CỦA FILE (tổng thể): các nhóm menu -> navigation layout ngang.
 * =====================================================================
 */
import apps from './apps'
import charts from './charts'
import dashboard from './dashboard'
import forms from './forms'
import pages from './pages'
import tables from './tables'
import uiElements from './ui-elements'

export default [...dashboard, ...apps, ...pages, ...uiElements, ...forms, ...tables, ...charts]
