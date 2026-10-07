# Chấm sơ bộ 19 bài AI · Codex · 07/10/2026

**Đã đọc và chấm 19/19 bài có nội dung; Q33 chưa có bài.** Điểm văn phong trung bình **4.49/5**, trung vị **4.4/5**. Trong phạm vi các snapshot đã lưu, tôi chưa phát hiện lỗi dữ kiện quan trọng ở 19 bài. **Q18 cần biên tập nhiều nhất về cách viết.**

Đây là **một lượt đánh giá của AI**, theo yêu cầu chủ dự án, để tham khảo và xác định việc cần sửa. Hai phiếu người đọc vẫn trống và nghiệm thu vẫn chờ. Tôi đã biết nhánh C và xem số liệu kỹ thuật trước đây nên không gọi đây là chấm mù hay đánh giá độc lập của con người.

## Cách đọc điểm

Đối chiếu từng source block với title/content của Qxx-C gốc, theo brief và [rubric](../../../quality/AI_ARTICLE_QUALITY_EVALUATION.md). Không lấy kết quả Analyze hoặc gate kỹ thuật làm đáp án. Nguồn là bản đã đóng băng; không xác minh trực tuyến tính đúng/hiện hành của dữ liệu, không chạy code hoặc xem pixel ảnh.

Năm điểm 1–5 lần lượt là **tự nhiên, hữu ích, cấu trúc, ít lặp, đúng brief**. 1 kém, 3 dùng được sau sửa, 5 tốt. “TB” chỉ là trung bình năm điểm văn phong; lỗi dữ kiện được xét riêng. “Đạt” dưới đây chỉ có nghĩa **chưa phát hiện sai dữ kiện quan trọng so với phạm vi nguồn đã đọc**, không phải duyệt đăng hoặc nghiệm thu chất lượng.

Danh sách 185 đơn vị dữ kiện được tôi đề xuất từ nguồn theo brief, chưa có người đọc xác nhận. Một đơn vị có thể gộp một claim với điều kiện đi kèm. Vì vậy, không diễn giải số đếm này thành “100% mọi thông tin đều đúng”.

| Bài | Nội dung | Tự nhiên | Hữu ích | Cấu trúc | Ít lặp | Brief | TB /5 | Dữ kiện / nguồn | Nhận xét |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | --- | --- |
| [Q01](#q01) | Reactivity Transform | 5 | 5 | 5 | 5 | 5 | 5.0 | Đạt · 4/4 | Ngắn, đủ thay đổi và điều kiện. |
| [Q02](#q02) | Quy tắc phiên bản Laravel | 4 | 5 | 5 | 4 | 4 | 4.4 | Đạt · 10/10 | Đúng facts; lặp một số quy tắc. |
| [Q03](#q03) | Nâng cấp Laravel 11 → 12 | 5 | 4 | 5 | 5 | 5 | 4.8 | Đạt · 4/4 | Đúng trích đoạn; nguồn chưa có cách nâng cấp. |
| [Q06](#q06) | Thử Vue trực tuyến | 4 | 5 | 5 | 4 | 5 | 4.6 | Đạt · 4/4 | Đúng 4 lựa chọn; nguồn không có CLI. |
| [Q07](#q07) | Cấu hình Laravel | 5 | 5 | 5 | 4 | 4 | 4.6 | Đạt · 6/6 | Giữ đủ 3 lệnh; rút ghi chú cuối. |
| [Q08](#q08) | Vue và TypeScript | 4 | 4 | 5 | 4 | 4 | 4.2 | Đạt · 25/25 | Đủ key/phiên bản; dày thuật ngữ. |
| [Q09](#q09) | Watcher trong Vue | 4 | 5 | 4 | 4 | 4 | 4.2 | Đạt · 10/10 | Giữ 7 code block; rút diễn giải lặp. |
| [Q12](#q12) | Composition API | 4 | 4 | 5 | 4 | 4 | 4.2 | Đạt · 11/11 | Đúng phiên bản/ví dụ; giải nghĩa thuật ngữ. |
| [Q13](#q13) | Vue 3 cho người mới | 5 | 5 | 5 | 5 | 5 | 5.0 | Đạt · 12/12 | Giải thích tốt cho người mới. |
| [Q14](#q14) | Cơ chế phản ứng | 4 | 5 | 5 | 4 | 5 | 4.6 | Đạt · 11/11 | Rõ mô hình; rút ghi chú lặp. |
| [Q17](#q17) | v-text | 5 | 5 | 5 | 5 | 5 | 5.0 | Đạt · 5/5 | Ngắn, rõ rủi ro ghi đè. |
| [Q18](#q18) | Ovation of the Seas | 4 | 4 | 4 | 3 | 3 | 3.6 | Đạt · 27/27 | Giữ số/bảng; cảnh báo lặp, cần biên tập. |
| [Q26](#q26) | Metro số 1 | 4 | 5 | 5 | 4 | 4 | 4.4 | Đạt · 6/6 | Đúng mốc/attribution; rút khách dự. |
| [Q27](#q27) | Năm Du lịch Huế | 4 | 5 | 4 | 4 | 4 | 4.2 | Đạt · 9/9 | Giữ 4 mùa; sắp lại bối cảnh họp báo. |
| [Q28](#q28) | Lễ hội cà phê | 4 | 5 | 5 | 4 | 4 | 4.4 | Đạt · 9/9 | Giữ dự kiến và các số lịch sử. |
| [Q29](#q29) | Du lịch năm 2024 | 4 | 5 | 5 | 4 | 4 | 4.4 | Đạt · 8/8 | Đúng kỳ/đơn vị; câu hành chính dài. |
| [Q30](#q30) | Vé bảo tàng năm 2010 | 5 | 5 | 5 | 5 | 5 | 5.0 | Đạt · 9/9 | Giữ đúng điều kiện; không đoán mốc 100. |
| [Q31](#q31) | Phong Nha | 4 | 4 | 5 | 4 | 4 | 4.2 | Đạt · 7/7 | Giữ khoảng 200; nguồn giới hạn. |
| [Q32](#q32) | Hội An | 5 | 4 | 5 | 5 | 4 | 4.6 | Đạt · 8/8 | Tự nhiên; không bịa 7 hoạt động. |
| [Q33](#q33) | Bảng GitHub Actions | — | — | — | — | — | — | Chưa có bài | Timeout, không có bài để chấm. |

Trung vị từng tiêu chí: **tự nhiên 4/5; hữu ích 5/5; cấu trúc 5/5; ít lặp 4/5; đúng brief 4/5**. Không phát hiện critical/major hoặc lỗi factual nhỏ trong phạm vi facts được đề xuất; nhận xét câu chữ ở phần sau là việc biên tập, không đổi thành lỗi dữ kiện. **Phút sửa và số sửa thực tế chưa đo, giữ trống.**

## Việc nên sửa trước

1. **Q18:** gom cảnh báo nguồn cũ vào một mục chung. Giữ cảnh báo riêng cho số Internet có đơn vị bất thường, điều kiện an toàn và giá thiếu phạm vi. Bài hiện giống bản rà soát tư liệu hơn một bài giới thiệu trải nghiệm.
2. **Q08, Q09, Q12:** rút giải thích lặp, giải nghĩa thuật ngữ cho người mới. Giữ code, key/value và điều kiện.
3. **Bổ sung nguồn ở phiên corpus mới:** Q03 chỉ có mục lục; Q06 không có CLI; Q12 chưa có so sánh trade-off; Q14 chưa có implementation; Q26 thiếu mốc bắt đầu vận hành. Các nguồn hiện tại chưa đủ để chứng minh toàn bộ coverage mục tiêu.
4. **Kiểm liên kết kế thừa từ nguồn trước khi đăng:** Q09/Q12/Q17 có href dạng `github.com/api/...` hoặc `github.com/guide/...`. Output giữ nguyên URL của snapshot. Lượt chấm này chưa mở URL nên chưa kết luận chúng hoạt động hay hỏng.
5. **Q33:** cần một phiên C mới có output để chấm bảng. Giữ ca timeout của study gốc trong mẫu số, không thay bằng một bài khác.

Chưa sửa bài, prompt hoặc cấu hình để thực hiện các đề xuất này. Không có lượt gọi provider mới trong project.

## Dẫn chứng từng bài

### Q01

**Reactivity Transform** · [Nguồn](../../task2-quality/corpus-v2/Q01.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q01-C.json)

Điểm: **5.0/5 văn phong**, **4/4 đơn vị dữ kiện đề xuất giữ đúng**.

Tin ngắn gọn, mở ngay bằng thay đổi chính, giữ hai liên kết tham khảo và hai điều kiện sử dụng. Không thấy phần kéo dài hoặc bịa hướng dẫn.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S002 | Reactivity Transform từng là tính năng thử nghiệm và bị loại bỏ ở Vue 3.4. | từng là tính năng thử nghiệm; đã bị loại bỏ trong Vue 3.4 |
| S003 | Nếu vẫn muốn dùng thì có thể dùng qua plugin Vue Macros. | qua plugin Vue Macros |
| S004 | Tính năng chỉ dành cho Composition API. | chỉ dành cho Composition API |
| S004 | Cần một bước build. | cần một bước build |

Chưa có đề xuất sửa câu chữ bắt buộc trong phạm vi này.

Bảo toàn: nguồn không có khối pre để so sánh. Không có metadata ảnh.

Giới hạn và việc cần xác nhận:

- Chỉ chấm thông báo ngắn trong snapshot; chưa kiểm tình trạng plugin hiện nay.

### Q02

**Quy tắc phiên bản Laravel** · [Nguồn](../../task2-quality/corpus-v2/Q02.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q02-C.json)

Điểm: **4.4/5 văn phong**, **10/10 đơn vị dữ kiện đề xuất giữ đúng**.

Giữ đúng nhịp phát hành, ^12.0 và ngoại lệ named arguments; diễn giải mục tiêu một ngày đúng mức. Mở bài và phần sau lặp lại vài quy tắc.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S006 | Laravel và các gói chính chủ tuân theo Semantic Versioning. | Laravel và các gói chính chủ khác tuân theo Semantic Versioning |
| S006 | Bản major phát hành hằng năm khoảng quý I. | phát hành hằng năm, vào khoảng quý I |
| S006 | Minor và patch có thể phát hành thường xuyên tới mức hằng tuần. | có thể ra thường xuyên tới mức hằng tuần |
| S006 | Minor và patch không được chứa breaking changes. | các bản này không được chứa thay đổi phá vỡ tương thích |
| S007 | Luôn dùng ràng buộc phiên bản như ^12.0 khi khai báo framework/components. | bạn nên luôn dùng ràng buộc phiên bản như ^12.0 |
| S007 | Bản major có breaking changes. | các bản major có thay đổi phá vỡ tương thích |
| S007 | Nâng cấp trong một ngày hoặc ít hơn là mục tiêu của Laravel, không phải bảo đảm cho mọi ứng dụng. | Đây là mục tiêu của dự án, không phải bảo đảm |
| S009 | Named arguments không thuộc hướng dẫn tương thích ngược. | Cách gọi này không thuộc phạm vi hướng dẫn về tương thích ngược của Laravel |
| S009 | Laravel có thể đổi tên tham số khi cần cải thiện codebase. | dự án có thể đổi tên tham số khi cần cải thiện mã nguồn |
| S009 | Cần dùng named arguments thận trọng vì tên tham số có thể thay đổi. | cần tính đến khả năng tên tham số thay đổi trong tương lai |

Cần biên tập:

- **Bản major của Laravel có thay đổi phá vỡ tương thích**: Ý major/minor/patch được nhắc lại ở mở bài, danh sách và phần nâng cấp. Rút mở bài còn vấn đề tương thích và ngoại lệ named arguments; giữ chi tiết trong từng mục.

Bảo toàn: nguồn không có khối pre để so sánh. Không có metadata ảnh.

Giới hạn và việc cần xác nhận:

- Ba liên kết mục lục đầu nguồn không có trong bài mới; đây là điều hướng, không phải mất nội dung body.
- Chưa chấm độ đúng hiện hành ngoài commit nguồn.

### Q03

**Nâng cấp Laravel 11 → 12** · [Nguồn](../../task2-quality/corpus-v2/Q03.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q03-C.json)

Điểm: **4.8/5 văn phong**, **4/4 đơn vị dữ kiện đề xuất giữ đúng**.

Đúng phạm vi trích đoạn và có ích như bản chỉ dẫn đọc tiếp. Bài không tự bịa lệnh, phiên bản phụ thuộc hoặc điều kiện triển khai khi nguồn chưa cung cấp.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S002 | Phạm vi nâng cấp là từ Laravel 11.x lên 12.0. | nâng cấp Laravel từ 11.x lên 12.0 |
| S003 | Hai mục thuộc nhóm High Impact Changes. | nhóm thay đổi có tác động lớn (High Impact Changes) |
| S004 | Một mục là cập nhật dependencies. | cập nhật các phụ thuộc (dependencies) |
| S005 | Mục còn lại là cập nhật Laravel Installer. | cập nhật Laravel Installer |

Chưa có đề xuất sửa câu chữ bắt buộc trong phạm vi này.

Bảo toàn: nguồn không có khối pre để so sánh. Không có metadata ảnh.

Giới hạn và việc cần xác nhận:

- Nguồn chỉ là tiêu đề và liên kết mục lục. Không đủ để kiểm chất lượng một hướng dẫn migration/breaking changes hoàn chỉnh.

### Q06

**Thử Vue trực tuyến** · [Nguồn](../../task2-quality/corpus-v2/Q06.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q06-C.json)

Điểm: **4.6/5 văn phong**, **4/4 đơn vị dữ kiện đề xuất giữ đúng**.

Bốn lựa chọn được phân biệt theo nhu cầu; URL và điều kiện lựa chọn giữ đúng. Một số nhãn và mô tả nhắc lại cùng ý.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S004 | Playground để thử Vue nhanh. | Thử nhanh với Playground |
| S005 | JSFiddle là điểm bắt đầu cho HTML thuần không có bước build. | HTML thuần mà không có bước build |
| S006 | Người quen Node.js và build tools có thể thử thiết lập build đầy đủ trong trình duyệt trên StackBlitz. | nếu đã quen với Node.js và khái niệm công cụ build |
| S007 | Scrimba là hướng dẫn tương tác về thiết lập khuyến nghị, chạy, sửa và triển khai ứng dụng Vue đầu tiên. | cách chạy, chỉnh sửa, triển khai ứng dụng Vue đầu tiên |

Cần biên tập:

- **thử Vue trực tiếp để làm quen nhanh**: Nhãn Thử nhanh và câu mô tả lặp lại cùng tác dụng. Gộp nhãn với mô tả thành một câu ngắn cho mỗi lựa chọn.

Bảo toàn: nguồn không có khối pre để so sánh. Không có metadata ảnh.

Giới hạn và việc cần xác nhận:

- Ca được đặt nhóm Tutorial CLI nhưng snapshot chỉ nói về bốn cách thử trực tuyến; chưa kiểm được thứ tự lệnh CLI.
- Frontmatter footer: false được bỏ đúng.

### Q07

**Cấu hình Laravel** · [Nguồn](../../task2-quality/corpus-v2/Q07.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q07-C.json)

Điểm: **4.6/5 văn phong**, **6/6 đơn vị dữ kiện đề xuất giữ đúng**.

Bố cục đi từ nơi lưu cấu hình tới xem tổng quan và chi tiết. Giữ chính xác cả ba lệnh Artisan, không thêm key hoặc yêu cầu môi trường chưa có trong nguồn.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S010 | Các tệp cấu hình Laravel nằm trong thư mục config. | nằm trong thư mục config |
| S010 | Mỗi option có tài liệu giải thích trong tệp. | Mỗi tùy chọn đều có phần giải thích trong tệp |
| S011 | Cấu hình gồm database, mail server, application URL và encryption key. | thông tin kết nối cơ sở dữ liệu, máy chủ thư, URL ứng dụng, khóa mã hóa |
| S013, S014 | about hiển thị tổng quan config, drivers và environment bằng php artisan about. | hiển thị tổng quan về cấu hình, driver và môi trường |
| S015, S016 | --only lọc một phần; ví dụ php artisan about --only=environment. | php artisan about --only=environment |
| S017, S018 | config:show xem chi tiết giá trị một file; ví dụ php artisan config:show database. | php artisan config:show database |

Cần biên tập:

- **Đoạn tài liệu được cung cấp không nêu phiên bản Laravel hoặc PHP**: Đoạn kết nói về quá trình cung cấp nguồn hơi nặng so với một hướng dẫn ngắn. Giữ ghi chú giới hạn phiên bản nếu cần; viết ngắn và tách khỏi nội dung thao tác.

Bảo toàn: 3/3 khối code nguồn giữ đúng sau giải mã HTML và bỏ whitespace đầu/cuối. Không có metadata ảnh.

Giới hạn và việc cần xác nhận:

- Các liên kết mục lục về environment/cache/secret khác không nằm trong phần body đã freeze; không yêu cầu tự viết thêm những phần đó.
- Chưa chạy ba lệnh trên một môi trường Laravel thực tế trong đợt chấm.

### Q08

**Vue và TypeScript** · [Nguồn](../../task2-quality/corpus-v2/Q08.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q08-C.json)

Điểm: **4.2/5 văn phong**, **25/25 đơn vị dữ kiện đề xuất giữ đúng**.

Coverage kỹ thuật tốt: giữ các key/value, đường dẫn IDE, mốc phiên bản và trạng thái tính năng đang phát triển. Hướng dẫn dài và nhiều thuật ngữ khiến người mới cần đọc chậm.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S003 | TypeScript giúp phát hiện lỗi bằng phân tích tĩnh, refactor và gợi ý kiểu trong IDE. | TypeScript có thể phát hiện nhiều lỗi phổ biến bằng phân tích tĩnh |
| S004 | Vue viết bằng TypeScript và mọi package chính thức có khai báo kiểu. | mọi gói Vue chính thức đều đi kèm khai báo kiểu |
| S006 | create-vue tạo dự án Vite có sẵn TypeScript. | có tùy chọn tạo dự án dùng Vite với TypeScript được thiết lập sẵn |
| S008 | Vite dev server/bundler chỉ transpile, không type-check. | dev server và bundler của Vite chỉ chuyển đổi mã, không kiểm tra kiểu |
| S009 | IDE phù hợp để phản hồi lỗi kiểu tức thì. | nhận phản hồi tức thì về lỗi kiểu |
| S010 | vue-tsc bọc tsc, hỗ trợ SFC, kiểm tra kiểu CLI và sinh declaration. | công cụ bọc tsc; sinh khai báo kiểu |
| S010 | Có thể chạy vue-tsc watch song song với Vite hoặc dùng checker ở worker thread. | ở chế độ watch song song; một worker thread riêng |
| S011 | Vue CLI hỗ trợ TS nhưng không còn được khuyến nghị. | Vue CLI có hỗ trợ TypeScript nhưng không còn được tài liệu này khuyến nghị |
| S013 | VS Code được khuyến nghị; Vue - Official trước tên Volar hỗ trợ TS trong SFC. | Vue - Official, trước đây có tên Volar |
| S013 | Vue - Official thay Vetur; cần tắt Vetur trong dự án Vue 3. | Nếu đã cài Vetur, hãy vô hiệu hóa extension này trong dự án Vue 3 |
| S014 | WebStorm hỗ trợ Vue/TS; IDE JetBrains khác hỗ trợ sẵn hoặc qua plugin miễn phí. | Các IDE JetBrains khác hỗ trợ trực tiếp hoặc thông qua plugin miễn phí |
| S014 | Mốc 2023.2 cho Vue Language Server trong WebStorm và Vue Plugin. | từ phiên bản 2023.2, WebStorm và Vue Plugin tích hợp sẵn Vue Language Server |
| S014 | Có đường dẫn Settings để bật Volar cho mọi TS; mặc định TS 5.0 trở lên. | Settings > Languages & Frameworks > TypeScript > Vue; TypeScript 5.0 trở lên |
| S016 | create-vue có tsconfig sẵn, nền @vue/tsconfig và Project References phân biệt môi trường. | Project References để bảo đảm kiểu phù hợp với từng môi trường chạy |
| S018 | isolatedModules=true do Vite/esbuild chuyển đổi từng tệp. | compilerOptions.isolatedModules; Vite dùng esbuild |
| S018 | verbatimModuleSyntax bao hàm isolatedModules và được @vue/tsconfig dùng. | tùy chọn bao hàm các ràng buộc của isolatedModules |
| S019 | Options API cần strict hoặc noImplicitThis; nếu không this là any. | compilerOptions.noImplicitThis; this trong các tùy chọn component sẽ được coi là any |
| S020 | Alias trong build tool cần khai báo paths cho TS, gồm @/* của create-vue. | cấu hình cho TypeScript qua compilerOptions.paths |
| S021 | TSX cần jsx=preserve và jsxImportSource=vue. | compilerOptions.jsx thành "preserve"; compilerOptions.jsxImportSource thành "vue" |
| S026 | Type checker cần toàn bộ module graph nên không phù hợp đặt trong bước transform từng module. | kiểm tra kiểu cần biết toàn bộ đồ thị module |
| S027 | ts-loader chỉ kiểm mã sau transform nên không khớp với IDE/vue-tsc ánh xạ source. | ts-loader chỉ kiểm tra kiểu của mã sau chuyển đổi |
| S028 | Kiểm kiểu cùng thread/process với transform làm chậm build. | ảnh hưởng đáng kể đến tốc độ build toàn ứng dụng |
| S029 | IDE đã type-check ở process riêng; làm chậm dev vì transform là trade-off không hợp lý. | IDE đã kiểm tra kiểu trong một process riêng |
| S030 | Vue 3 + TS qua Vue CLI được khuyến nghị chuyển sang Vite. | tài liệu khuyến nghị chuyển sang Vite |
| S030 | CLI transpile-only là công việc đang thực hiện, chưa xác nhận đã phát hành. | không phải xác nhận tính năng đã phát hành |

Cần biên tập:

- **kiểm tra kiểu cần biết toàn bộ đồ thị module**: Lý do tách type checking được trình bày ở mở bài rồi quay lại giải thích dài ở phần ts-loader. Rút mở bài; dành giải thích chi tiết cho mục ts-loader và thêm một câu dẫn đường cho người mới.
- **một worker thread riêng**: Một số thuật ngữ worker thread/module graph xuất hiện chưa có giải nghĩa ngắn. Giải thích ngay khi xuất hiện; giữ nguyên mọi điều kiện và key cấu hình.

Bảo toàn: nguồn không có khối pre để so sánh. Không có metadata ảnh.

Giới hạn và việc cần xác nhận:

- Không cài IDE/build tools hoặc chạy kiểm tra kiểu trong đợt chấm.
- Snapshot có mốc 2023.2 và TS 5.0; điểm chỉ phản ánh trung thành nguồn, không xác nhận công cụ hiện nay.

### Q09

**Watcher trong Vue** · [Nguồn](../../task2-quality/corpus-v2/Q09.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q09-C.json)

Điểm: **4.2/5 văn phong**, **10/10 đơn vị dữ kiện đề xuất giữ đúng**.

Giữ toàn bộ bảy khối code, xử lý try/catch/finally và giới hạn nguồn watch. Giải thích đủ cho người mới nhưng hai ví dụ đầy đủ cộng diễn giải khiến bài nặng.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S003 | Computed tính derived values; watcher dùng cho side effects như DOM/async-state. | Những tác động phụ này là trường hợp sử dụng watcher |
| S004, S005 | Options API watch chạy khi property reactive đổi; chỉ gọi API nếu question chứa ?. | chỉ gọi API lấy câu trả lời nếu câu hỏi chứa dấu ? |
| S005 | Ví dụ đặt loading, fetch yesno API, đọc answer, bắt lỗi và reset loading trong finally. | Khối finally đặt loading về false |
| S006 | Template dùng v-model question, disabled loading và hiển thị answer. | v-model liên kết ô nhập với question |
| S008, S009 | Dot path trong Options watch chỉ hỗ trợ đường dẫn đơn giản, không hỗ trợ biểu thức. | chỉ hỗ trợ đường dẫn đơn giản, không hỗ trợ biểu thức |
| S010, S011 | Composition watch nhận ref và callback async, cập nhật qua .value. | watch nhận trực tiếp ref question và một callback async |
| S014 | Nguồn watch có ref/computed ref, reactive object, getter hoặc mảng. | Một ref, kể cả computed ref. |
| S015 | Ví dụ theo dõi ref x, getter tổng x+y và mảng x/getter y. | theo dõi nhiều nguồn bằng mảng [x, () => y.value] |
| S016, S017 | Không watch trực tiếp obj.count vì đó là giá trị số. | Truyền giá trị đó vào watch không cung cấp nguồn reactive cần theo dõi |
| S018, S019 | Dùng getter () => obj.count. | hãy truyền getter () => obj.count |

Cần biên tập:

- **Luồng lấy câu trả lời và xử lý lỗi giống ví dụ Options API**: Sau khi đã có cả hai code mẫu đầy đủ, phần diễn giải còn nhắc lại nhiều bước. Giữ cả code theo yêu cầu bảo toàn; rút diễn giải ở ví dụ thứ hai chỉ còn điểm khác .value/watch.
- **Đối số đầu tiên của watch có thể là:**: Phần source types rồi phần giải thích code nhắc lại danh sách vừa nêu. Gộp diễn giải với ví dụ tương ứng để giảm chuyển qua lại.

Bảo toàn: 7/7 khối code nguồn giữ đúng sau giải mã HTML và bỏ whitespace đầu/cuối. Không có metadata ảnh.

Giới hạn và việc cần xác nhận:

- Các API link github.com/api/... đã nằm trong nguồn; chưa kiểm chúng trực tuyến.
- Chưa chạy ví dụ hoặc kiểm race condition/cancellation ngoài đoạn nguồn.

### Q12

**Composition API** · [Nguồn](../../task2-quality/corpus-v2/Q12.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q12-C.json)

Điểm: **4.2/5 văn phong**, **11/11 đơn vị dữ kiện đề xuất giữ đúng**.

Định nghĩa, phiên bản và ví dụ đúng. Việc nói rõ script setup là phổ biến thay vì bắt buộc và không xác nhận bảo trì plugin hiện tại là phù hợp. Người mới còn cần giải nghĩa dependency injection.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S003 | FAQ gốc giả định đã biết Vue, đặc biệt Vue 2/Options API. | FAQ gốc dành cho người đã có kinh nghiệm với Vue |
| S005 | Composition API dùng imported functions thay khai báo options. | bằng các hàm được import, thay vì khai báo các options |
| S006 | Reactivity API có ref/reactive để tạo reactive/computed/watchers. | trạng thái phản ứng, trạng thái tính toán và watcher |
| S007 | Lifecycle hooks gồm onMounted/onUnmounted. | onMounted() và onUnmounted() |
| S008 | Dependency Injection dùng provide/inject cùng reactivity. | provide() và inject() |
| S009 | Tích hợp sẵn ở Vue 3 và Vue 2.7. | tích hợp sẵn trong Vue 3 và Vue 2.7 |
| S009 | Vue 2 cũ hơn dùng @vue/composition-api, nguồn mô tả chính thức nhưng không xác nhận bảo trì hiện tại. | không phải xác nhận về tình trạng bảo trì hiện tại |
| S009 | Vue 3 thường dùng với script setup/SFC, không phải điều kiện bắt buộc. | không phải điều kiện bắt buộc |
| S010 | Ví dụ ref(0), tăng count.value khi click và onMounted log ban đầu. | increment() tăng count.value và kích hoạt cập nhật |
| S011 | Composition API không phải functional programming; Vue mutable/fine-grained, FP nhấn immutability. | Composition API không phải lập trình hàm |
| S012 | Nút API preference nằm ở đầu sidebar trái của trang docs; hướng dẫn đọc từ đầu. | chọn Composition API cho toàn bộ trang, rồi đọc hướng dẫn từ đầu |

Cần biên tập:

- **hệ thống dependency injection của Vue**: Định nghĩa ở phần Dependency Injection gần như lặp lại tên thuật ngữ. Thêm một giải nghĩa ngắn bằng tiếng Việt trong phạm vi nguồn; không bịa một ví dụ API mới.

Bảo toàn: 1/1 khối code nguồn giữ đúng sau giải mã HTML và bỏ whitespace đầu/cuối. Không có metadata ảnh.

Giới hạn và việc cần xác nhận:

- Ca gắn nhãn So sánh trade-off nhưng snapshot chưa có phần so sánh ưu/nhược; chưa kiểm được coverage mục tiêu này.
- Href github.com/api/... được bảo toàn từ snapshot, chưa kiểm hoạt động.
- Frontmatter outline: deep được bỏ đúng.

### Q13

**Vue 3 cho người mới** · [Nguồn](../../task2-quality/corpus-v2/Q13.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q13-C.json)

Điểm: **5.0/5 văn phong**, **12/12 đơn vị dữ kiện đề xuất giữ đúng**.

Bài giải thích rõ cả hai cách viết là lựa chọn thay thế, dùng một template chung và kết nối code với hai khái niệm chính. Giữ ba code block, link học nền và mốc EOL.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S003 | Nội dung là tài liệu Vue 3. | tài liệu Vue 3 |
| S004 | Vue 2 kết thúc hỗ trợ ngày 31/12/2023. | ngày 31/12/2023 |
| S005 | Có Migration Guide cho nâng cấp từ Vue 2. | hãy tham khảo Migration Guide |
| S007 | Vue là framework JS xây UI trên HTML/CSS/JS, declarative và component-based. | mô hình lập trình khai báo và dựa trên component |
| S007 | Vue phát âm /vjuː/, như view. | Tên Vue được phát âm /vjuː/ |
| S009 | Ví dụ data() count=0 mount #app. | Cách thứ nhất dùng data() |
| S010 | Ví dụ setup/ref(0) thay thế cùng mount #app. | Bạn dùng một trong hai khối JavaScript này |
| S011 | Template dùng @click=count++ và mustache count. | @click="count++" tăng biến đếm |
| S014 | Declarative rendering mô tả HTML dựa vào JS state. | mô tả đầu ra HTML dựa trên trạng thái JavaScript |
| S015 | Reactivity tự theo dõi state và cập nhật DOM. | Vue tự theo dõi thay đổi trạng thái JavaScript |
| S017 | Cần nền HTML/CSS/JS, người mới hoàn toàn nên học nền trước. | hãy nắm các kiến thức nền tảng trước |
| S017 | Kinh nghiệm framework khác hữu ích nhưng không bắt buộc. | Kinh nghiệm với framework khác có ích, nhưng không bắt buộc |

Chưa có đề xuất sửa câu chữ bắt buộc trong phạm vi này.

Bảo toàn: 3/3 khối code nguồn giữ đúng sau giải mã HTML và bỏ whitespace đầu/cuối. Không có metadata ảnh.

Giới hạn và việc cần xác nhận:

- Code được đối chiếu với bản đã lưu, chưa chạy. Các ký tự escape trong snapshot cần được kiểm khi xuất code để dùng thực tế.
- Frontmatter footer: false không phải nội dung cần giữ.

### Q14

**Cơ chế phản ứng** · [Nguồn](../../task2-quality/corpus-v2/Q14.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q14-C.json)

Điểm: **4.6/5 văn phong**, **11/11 đơn vị dữ kiện đề xuất giữ đúng**.

Mạch từ phép gán tới effect/dependency rõ, giữ ba khối code và ba nhiệm vụ cơ chế phản ứng. Không biến hàm minh họa thành API Vue thật.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S003 | Vue component state gồm object JS reactive, sửa state làm view cập nhật. | khi bạn chỉnh sửa chúng, giao diện cập nhật |
| S005 | Reactivity là thích ứng thay đổi theo cách khai báo. | thích ứng với thay đổi theo cách khai báo |
| S006 | Ví dụ bảng tính A2=A0+A1, kết quả 3, A0/A1 đổi thì A2 đổi. | Khi A0 hoặc A1 thay đổi, A2 tự động cập nhật |
| S008, S009 | JS gán A0=2 nhưng A2 vẫn 3, không tự tính lại. | A2 vẫn là 3 |
| S010, S011 | Bọc phép cập nhật A2 trong hàm update để có thể chạy lại. | ta viết lại phần cập nhật A2 thành một hàm |
| S013 | update là effect vì sửa trạng thái chương trình. | vì nó thay đổi trạng thái chương trình bằng cách cập nhật A2 |
| S014 | A0/A1 là dependencies; effect là subscriber. | A0 và A1 là các phụ thuộc của effect |
| S015, S016 | whenDepsChange(update) minh họa cơ chế gọi lại khi dependency đổi, chưa phải API được triển khai. | hàm giả định để giải thích cơ chế cần có |
| S018 | Theo dõi lúc một biến được đọc. | Theo dõi lúc biến được đọc. |
| S019 | Khi effect đang chạy, đăng ký effect với các biến được đọc; sau lần đầu update subscribe A0/A1. | sau lần gọi update() đầu tiên |
| S020 | Phát hiện mutation và thông báo subscriber effects chạy lại. | thông báo cho tất cả effect đã đăng ký |

Cần biên tập:

- **Đây là mô hình khái niệm mà đoạn tài liệu dùng**: Giới hạn mô hình khái niệm đã được nói ngay sau whenDepsChange và lặp ở kết. Giữ một ghi chú rõ rằng đây là hàm giả định, rút ghi chú lặp cuối.

Bảo toàn: 3/3 khối code nguồn giữ đúng sau giải mã HTML và bỏ whitespace đầu/cuối. Không có metadata ảnh.

Giới hạn và việc cần xác nhận:

- Snapshot chỉ là phần mở đầu khái niệm, chưa có Proxy/track/trigger để kiểm một bài chuyên sâu về implementation.
- Ký hiệu bảng tính được giữ theo nguồn; chưa xác minh hoặc chạy ví dụ Excel.

### Q17

**v-text** · [Nguồn](../../task2-quality/corpus-v2/Q17.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q17-C.json)

Điểm: **5.0/5 văn phong**, **5/5 đơn vị dữ kiện đề xuất giữ đúng**.

Ngắn và đủ: nêu ngay rủi ro ghi đè, phân biệt cập nhật toàn bộ/một phần, giữ code mẫu và link nguồn. Không kéo thành bài dài.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S003, S005 | v-text gán textContent để cập nhật text. | bằng cách gán thuộc tính textContent |
| S004 | Giá trị mong đợi là string. | mong đợi là string (chuỗi) |
| S005 | v-text ghi đè toàn bộ nội dung bên trong phần tử. | ghi đè toàn bộ nội dung hiện có bên trong |
| S005 | Cần cập nhật một phần thì dùng mustache interpolation. | chỉ cập nhật một phần văn bản |
| S006, S007 | Hai ví dụ span v-text=msg và span mustache msg tương đương. | hai cách viết tương đương nhau |

Chưa có đề xuất sửa câu chữ bắt buộc trong phạm vi này.

Bảo toàn: 1/1 khối code nguồn giữ đúng sau giải mã HTML và bỏ whitespace đầu/cuối. Không có metadata ảnh.

Giới hạn và việc cần xác nhận:

- Link github.com/guide/... được giữ từ snapshot; chưa kiểm hoạt động.
- Khối mustache thứ hai được đưa từ ví dụ inline trong source sang pre; chưa chạy code.

### Q18

**Ovation of the Seas** · [Nguồn](../../task2-quality/corpus-v2/Q18.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q18-C.json)

Điểm: **3.6/5 văn phong**, **27/27 đơn vị dữ kiện đề xuất giữ đúng**.

Dữ kiện tàu và sáu dòng tour được giữ đúng. Bài phân biệt dữ liệu cũ, quảng bá và điều chưa xác minh tốt, nhưng giọng giống bản kiểm toán: hầu như mỗi mục đều có đoạn cảnh báo. Cần sửa cách trình bày trước khi dùng làm bài giới thiệu trải nghiệm.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S001 | Star Travel đón Ovation cập cảng Việt Nam ngày 14/06/2016. | ngày 14/06/2016 |
| S002 | Hơn 1.213 lượt khách Star Travel trong sáu tháng đầu năm 2016 bằng Royal Caribbean. | hơn 1.213 lượt khách tham quan bằng hệ thống du thuyền Royal Caribbean |
| S003 | Tàu thuộc Quantum, trong nhóm ba tàu nguồn nhắc. | thuộc dòng Quantum, trong nhóm ba du thuyền |
| S003 | Quy mô 18 tầng, dài 347m, ngang 41m. | 18 tầng, dài 347 mét, ngang 41 mét |
| S003 | 2.090 phòng, 4.905 khách, 1.500 nhân viên. | 2.090 phòng ngủ, sức chứa 4.905 khách và 1.500 nhân viên |
| S003 | Nguồn gọi 168.666 tấn là tải trọng; không đổi loại đại lượng chưa xác minh. | Con số 168.666 tấn được gọi là “tải trọng” |
| S005 | Sao Bắc Đẩu cabin/cần trục cao hơn 90m và góc nhìn 360 độ. | độ cao hơn 90 mét so với mặt nước biển |
| S007 | Two70 ăn uống/giải trí, nhìn 270 độ, ngày sáng và tối cửa sổ thành màn hình. | tầm nhìn 270 độ; các cửa sổ trở thành màn hình trình chiếu lớn |
| S009 | Internet vệ tinh và số 4,26 km/giây từ nguồn phải giữ ở trạng thái bất thường, không đổi thành Mbps. | không nên tự đổi thành Mbps hoặc Gbps |
| S009 | WOWband được mô tả cấp mỗi khách để check-in/out, mở phòng, thanh toán. | mỗi hành khách được cấp vòng tay thông minh |
| S011 | Bionic chọn tablet, hai robot và hai ly/phút; không tự gán năng suất mỗi robot. | không nói rõ đó là năng suất chung hay của từng robot |
| S013 | Ripcord by iFly trải nghiệm bay theo quảng bá; chưa có điều kiện an toàn cho mọi người. | không phải bảo đảm an toàn cho mọi người |
| S015 | SeaPlex có danh sách trượt băng, bóng rổ, xe điện đụng, nhào lộn theo nguồn. | trượt băng nghệ thuật, bóng rổ, xe điện đụng và biểu diễn nhào lộn |
| S017 | Loft Suite hai tầng, ban công biển, LED 80-inch, piano. | hai tầng, ban công riêng hướng biển, màn hình LED 80-inch và một chiếc piano |
| S017 | Chỉ một số phòng có ban công màn hình ảo. | Một số phòng được giới thiệu có ban công màn hình ảo |
| S019 | 18 nhà hàng, trong đó nhà hàng Ý được mô tả Jamie Oliver chỉ đạo. | 18 nhà hàng, trong đó có nhà hàng Ý |
| S021 | Tour Singapore-Malaysia 03/04/2017, 4 ngày, 19.000.000đ. | 03/04/2017; 4 ngày; 19.000.000đ |
| S022 | Tour Singapore-Malaysia 10/04/2017, 5 ngày, 20.500.000đ. | 10/04/2017; 20.500.000đ |
| S023 | Tour Singapore-Thái Lan 11/03 và 06/04/2017, 5 ngày, 20.900.000đ. | 11/03/2017, 06/04/2017 |
| S024 | Tour Singapore-Malaysia 15/03/2017, 5 ngày, 20.900.000đ. | 15/03/2017 |
| S025 | Tour Singapore-Thái Lan-Malaysia 06/03,19/03,29/03/2017, 6 ngày, 24.900.000đ. | 06/03/2017, 19/03/2017, 29/03/2017; 24.900.000đ |
| S026 | Tour Singapore-Malaysia-Thái Lan 24/03/2017, 6 ngày, 25.500.000đ. | 24/03/2017; 25.500.000đ |
| S020, S021, S022, S023, S024, S025, S026 | Giá trọn gói và lịch là dữ liệu năm 2017, không báo giá hiện hành hoặc chứng minh bao gồm mọi phí. | Bảng dưới đây là thông tin lịch sử, không phải báo giá hiện hành. |
| S027 | Trụ sở 96 Trần Hưng Đạo Q1, hotline 0925 122 122, tel (08) 39 201 201. | 96 Trần Hưng Đạo; 0925 122 122; (08) 39 201 201 |
| S028 | VP Hà Nội tầng3 59 Xã Đàn Đống Đa, tel (04)39 275 288. | tầng 3, 59 Xã Đàn; (04) 39 275 288 |
| S029 | VP PMH SE3-1 Mỹ Khánh3 Q7, tel (08)54 121 168. | SE3-1 Mỹ Khánh 3; (08) 54 121 168 |
| S029 | Giữ email và nhãn website nguồn, không đánh tráo nhãn với href. | Nhãn và đích liên kết được giữ nguyên |

Cần biên tập:

- **không phải lịch chạy hay báo giá hiện nay**: Giới hạn dữ liệu cũ được nhắc ở mở bài, WOWband, SeaPlex, nhà hàng, bảng tour, liên hệ và kết. Gom cảnh báo tính hiện hành thành một ghi chú chung; chỉ giữ cảnh báo riêng cho đơn vị Internet sai, an toàn và giá thiếu điều kiện.
- **không đủ chi tiết để khẳng định cấu hình này có ở mọi Loft Suite**: Đoạn giới hạn lặp mô thức nguồn chưa đủ trên nhiều mục, làm trải nghiệm đọc bị ngắt. Viết phần tiện ích liền mạch và dành một mục cuối cho những thông tin cần xác nhận.
- **Thông tin liên hệ trong nguồn cũ**: Ba địa chỉ và điện thoại cũ kéo bài xa mục đích giới thiệu trải nghiệm. Cân nhắc chuyển liên hệ sang phụ lục giữ nguyên dữ liệu, tùy phạm vi bài người dùng muốn.

Bảo toàn: nguồn không có khối pre để so sánh. Nguồn có 11 metadata ảnh; chưa chấm ảnh hoặc regenerate. Sáu dòng tour đã đối chiếu từng điểm đến, ngày, thời lượng và giá.

Giới hạn và việc cần xác nhận:

- S002 còn nêu quan hệ đại diện/đối tác Royal Caribbean. Bài bỏ chi tiết doanh nghiệp này; dưới brief giới thiệu trải nghiệm tàu, tôi xem đây là chi tiết phụ. Người đọc cần xác nhận lại phạm vi facts phải giữ.
- Nguồn có đơn vị Internet bất thường, tên Two700/Two70 không nhất quán và lời quảng bá. Điểm fidelity không xác nhận tính đúng bên ngoài nguồn.
- Có 11 metadata ảnh, chưa nhìn pixel hoặc kiểm ảnh được gắn vào bài.

### Q26

**Metro số 1** · [Nguồn](../../task2-quality/corpus-v2/Q26.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q26-C.json)

Điểm: **4.4/5 văn phong**, **6/6 đơn vị dữ kiện đề xuất giữ đúng**.

Giữ ngày, tuyến, địa điểm và người phát biểu; không suy khánh thành thành ngày bắt đầu vận hành. Tin ba đoạn phù hợp lượng nguồn, nhưng phần danh sách khách dự hơi dài.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S002, S006 | Lễ khánh thành diễn ra sáng 09/03/2025. | Sáng 09/03/2025 |
| S006 | UBND TPHCM tổ chức tại Công viên 23/9 Quận1. | UBND TPHCM tổ chức lễ khánh thành |
| S006 | Tên tuyến Metro số1 Bến Thành-Suối Tiên. | Metro số 1) Bến Thành - Suối Tiên |
| S006 | Khách dự có Nhật Bản, lãnh đạo, đối tác trong/ngoài nước và người dân. | đại diện Chính phủ Nhật Bản |
| S003 | Nhận xét về chương mới giao thông là lời Chủ tịch UBND TPHCM, không trải nghiệm tác giả. | Chủ tịch UBND TPHCM nhấn mạnh |
| S002 | Bản tin đăng 10:55 ngày 09/03/2025. | đăng lúc 10:55 cùng ngày |

Cần biên tập:

- **Tham dự buổi lễ có đại diện Chính phủ Nhật Bản**: Đoạn khách dự dài và giọng hành chính; có thể giảm nhịp đọc. Rút nhóm chức danh lặp, giữ các nhóm tham dự có ý nghĩa và attribution phát biểu.

Bảo toàn: nguồn không có khối pre để so sánh. Nguồn có 1 metadata ảnh; chưa chấm ảnh hoặc regenerate.

Giới hạn và việc cần xác nhận:

- Snapshot không chứa ngày bắt đầu vận hành; ca này chưa kiểm được khả năng giữ hai mốc riêng khi cả hai xuất hiện.
- Caption ảnh là metadata; chưa kiểm pixel/hiển thị ảnh.

### Q27

**Năm Du lịch Huế** · [Nguồn](../../task2-quality/corpus-v2/Q27.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q27-C.json)

Điểm: **4.2/5 văn phong**, **9/9 đơn vị dữ kiện đề xuất giữ đúng**.

Các tên chương trình, bốn mùa và trạng thái kế hoạch đều giữ đúng. Đoạn họp báo đặt sau danh sách chương trình làm mạch thời gian hơi quay lại.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S001, S002 | Năm Du lịch quốc gia và Festival Huế năm2025. | Năm Du lịch quốc gia và Festival Huế năm 2025 |
| S001, S006 | Chủ đề Huế - Kinh đô xưa, vận hội mới. | Huế - Kinh đô xưa, vận hội mới |
| S002 | Dự kiến hơn170 sự kiện cấp quốc gia/cấp tỉnh. | dự kiến có hơn 170 sự kiện cấp quốc gia, cấp tỉnh |
| S002 | Mùa Xuân là Xuân Cố đô. | Lễ hội mùa Xuân “Xuân Cố đô” |
| S002 | Mùa Hạ là Kinh thành tỏa sáng. | Lễ hội mùa Hạ “Kinh thành tỏa sáng” |
| S002 | Mùa Thu là Huế vào Thu. | Lễ hội mùa Thu “Huế vào Thu” |
| S002 | Mùa Đông là Mùa Đông xứ Huế. | Lễ hội mùa Đông “Mùa Đông xứ Huế” |
| S006 | BTC họp báo sáng31/12/2024 tại Huế. | Sáng 31/12/2024, tại thành phố Huế |
| S002, S003 | Đăng14:57 ngày31/12/2024, đây là kế hoạch ở thời điểm công bố. | phản ánh kế hoạch tại thời điểm công bố |

Cần biên tập:

- **Sáng 31/12/2024, tại thành phố Huế**: Bối cảnh họp báo xuất hiện sau khi đã nêu kế hoạch và các nhóm chương trình. Đặt bối cảnh công bố cùng mở bài; phần sau chỉ trình bày bốn nhóm chương trình.

Bảo toàn: nguồn không có khối pre để so sánh. Nguồn có 1 metadata ảnh; chưa chấm ảnh hoặc regenerate.

Giới hạn và việc cần xác nhận:

- Trích đoạn cuối2024 chưa có lịch chi tiết hoặc kết quả tổ chức2025; không thể đánh giá hai phần đó.
- Không kiểm ảnh hoặc thực tế diễn ra sự kiện.

### Q28

**Lễ hội cà phê** · [Nguồn](../../task2-quality/corpus-v2/Q28.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q28-C.json)

Điểm: **4.4/5 văn phong**, **9/9 đơn vị dữ kiện đề xuất giữ đúng**.

Giữ số, kỳ niên vụ, tỷ lệ và khoảng ngày qua hai năm. Có attribution cho dữ liệu lịch sử và không biến kế hoạch thành sự kiện đã xảy ra.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S003 | Tên lễ hội di sản cà phê toàn cầu2025 tại Đà Lạt/Lâm Đồng. | dự kiến diễn ra tại Đà Lạt, tỉnh Lâm Đồng |
| S003 | Khoảng dự kiến18/12/2025-02/1/2026, qua hai năm. | từ ngày 18/12/2025 đến 02/1/2026 |
| S003 | Hơn500 đại biểu/chuyên gia, hàng trăm rang xay quốc tế, hàng ngàn du khách là dự kiến. | Sự kiện dự kiến quy tụ hơn 500 đại biểu |
| S002 | Bản tin25/11/2025 lúc15:15. | đăng ngày 25/11/2025 lúc 15:15 |
| S006 | Mốc150 năm cà phê bén rễ tại Việt Nam trong2025. | năm 2025 đánh dấu 150 năm |
| S006 | Niên vụ2024-2025 xuất khẩu8,4 tỷUSD. | niên vụ 2024-2025 đạt kỷ lục 8,4 tỷ USD |
| S006 | Mức kỷ lục là theo thời điểm nguồn, không khẳng định mãi mãi. | mức cao nhất tính đến thời điểm đăng bài |
| S006 | Nguồn mô tả Việt Nam xuất khẩu Robusta lớn nhất thế giới tại thời điểm đó. | khi đó Việt Nam là quốc gia xuất khẩu Robusta lớn nhất thế giới |
| S006 | Khoảng17% tổng sản lượng cà phê toàn cầu. | chiếm khoảng 17% tổng sản lượng cà phê toàn cầu |

Cần biên tập:

- **không phải thông tin xác nhận lễ hội đã diễn ra**: Câu xác nhận phạm vi kế hoạch hơi dài sau khi đã có dự kiến và ngày nguồn. Rút còn một ghi chú ngắn về kế hoạch tại thời điểm đăng; không bỏ từ dự kiến.

Bảo toàn: nguồn không có khối pre để so sánh. Nguồn có 1 metadata ảnh; chưa chấm ảnh hoặc regenerate.

Giới hạn và việc cần xác nhận:

- Chỉ chấm trung thành bản tin lịch sử; không kiểm kim ngạch, thị phần hoặc sự kiện bằng nguồn cập nhật.
- Có metadata một ảnh, chưa kiểm pixel.

### Q29

**Du lịch năm 2024** · [Nguồn](../../task2-quality/corpus-v2/Q29.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q29-C.json)

Điểm: **4.4/5 văn phong**, **8/8 đơn vị dữ kiện đề xuất giữ đúng**.

Kỳ báo cáo, lượt khách và đơn vị nghìn tỷ đồng được giữ đúng. Lời đánh giá được gắn với cơ quan phát biểu. Đoạn hai nhiều danh từ hành chính.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S004 | Số liệu thuộc năm2024. | Năm 2024, Việt Nam |
| S004 | Hơn17,5 triệu khách quốc tế. | hơn 17,5 triệu khách quốc tế |
| S004 | 110 triệu lượt khách du lịch nội địa. | 110 triệu lượt khách du lịch nội địa |
| S004 | Tổng thu840 nghìn tỷđồng. | 840 nghìn tỷ đồng |
| S004 | Hoàn thành chỉ tiêu kế hoạch đặt từ đầu năm. | hoàn thành chỉ tiêu kế hoạch đặt ra từ đầu năm |
| S004 | Đánh giá điểm sáng là của Chính phủ/Thủ tướng. | được Chính phủ, Thủ tướng Chính phủ đánh giá |
| S002, S003 | TITC thuộc CụcDuLịch xây tài liệu tháng12/2024; cập nhật07/01/2025. | cập nhật ngày 07/01/2025 |
| S003 | Tài liệu giới thiệu sự kiện/kết quả và quản lý, truyền thông, xúc tiến, quốc tế, số hóa. | quản lý nhà nước, truyền thông, xúc tiến quảng bá, hợp tác quốc tế và chuyển đổi số |

Cần biên tập:

- **các sự kiện tiêu biểu, dấu ấn nổi bật và kết quả thực hiện**: Danh sách nội dung tài liệu dài, gần văn phong thông cáo. Rút danh sách hoặc chia câu; giữ cơ quan, tên tài liệu, tháng và ngày cập nhật.

Bảo toàn: nguồn không có khối pre để so sánh. Nguồn có 1 metadata ảnh; chưa chấm ảnh hoặc regenerate.

Giới hạn và việc cần xác nhận:

- Không kiểm lại thống kê bằng dữ liệu hiện hành; đây là thông tin kỳ2024.
- Nguồn chỉ có đoạn giới thiệu tài liệu, chưa có bảng thống kê đầy đủ.

### Q30

**Vé bảo tàng năm 2010** · [Nguồn](../../task2-quality/corpus-v2/Q30.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q30-C.json)

Điểm: **5.0/5 văn phong**, **9/9 đơn vị dữ kiện đề xuất giữ đúng**.

Bài xử lý tốt một nguồn có ranh giới chưa rõ: giữ dưới100/trên100 và không tự đoán đoàn đúng100. Điều kiện nhóm, thứ trong tuần, tiền và tư liệu năm2010 đều rõ.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S001 | Thông báo áp dụng từ01/03/2010, phải giữ như tư liệu lưu trữ. | 01 tháng 03 năm 2010 |
| S002 | Mở tất cả ngày trong tuần. | mở cửa tất cả các ngày trong tuần |
| S002 | Giờ8h00-16h30. | từ 8h00 đến 16h30 |
| S003 | Khách Việt Nam và nước ngoài20.000đ/người/vé. | 20.000đ/người/vé |
| S004, S005 | Điều kiện học sinh chỉ từ Tiểu học tới THPT. | học sinh từ cấp Tiểu học đến Trung học phổ thông |
| S004 | Thứba và Thứsáu miễn phí với đoàn dưới100người. | miễn phí vé tham quan cho đoàn dưới 100 người |
| S004 | Đoàn trên100: từ người101 trả50%=10.000đ/người/vé. | số người từ 101 trở đi phải mua vé bằng 50% |
| S005 | Các thứ2,4,5,7,CN học sinh trả50%=10.000đ/người/vé. | Thứ hai, Thứ tư, Thứ năm, Thứ bảy và Chủ nhật |
| S004 | Nguồn chỉ nêu dưới100/trên100, không được tự bổ sung chính sách đúng100. | Trích đoạn không nói rõ điều kiện vé cho đoàn đúng 100 người |

Chưa có đề xuất sửa câu chữ bắt buộc trong phạm vi này.

Bảo toàn: nguồn không có khối pre để so sánh. Nguồn có 1 metadata ảnh; chưa chấm ảnh hoặc regenerate.

Giới hạn và việc cần xác nhận:

- Không thể dùng bài này như báo giá/lịch mở cửa hiện tại.
- Điều kiện đúng100người phải được bảo tàng xác nhận nếu dùng cho quyết định thực tế.

### Q31

**Phong Nha** · [Nguồn](../../task2-quality/corpus-v2/Q31.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q31-C.json)

Điểm: **4.2/5 văn phong**, **7/7 đơn vị dữ kiện đề xuất giữ đúng**.

Giữ hạn mức khoảng200 theo nguồn với cảnh báo không coi là hiện hành; không tự bịa danh sách hoạt động. Bài dễ đọc nhưng còn phụ thuộc đoạn nguồn ngắn nên thông tin hành động hạn chế.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S001, S003 | Phong Nha hấp dẫn người yêu thiên nhiên ngoài Sơn Đoòng. | không chỉ thu hút du khách nhờ Sơn Đoòng |
| S002 | Trong vài năm từ vùng yên ắng giáp Lào thành trung tâm du lịch. | vùng đất yên ắng giáp Lào này đã trở thành một trung tâm du lịch |
| S002 | Cảnh phủ rừng và đá vôi lâu đời nhất châu lục được trình bày theo nguồn. | những thành tạo đá vôi mà nguồn mô tả là lâu đời nhất châu Á |
| S001, S002 | Sơn Đoòng là hang lớn nhất theo nguồn và phát hiện góp phần nổi tiếng. | Việc phát hiện Sơn Đoòng góp phần làm Phong Nha được chú ý hơn |
| S003 | Khoảng200 người/năm vào Sơn Đoòng qua tour Oxalis. | khoảng 200 người mỗi năm |
| S003, S004 | Có hoạt động ngoài trời và lựa chọn khác cho người không thích nơi tối. | người không thích không gian tối vẫn có nhiều lựa chọn khác |
| S004 | Phần lớn tour trong khu vực có tham quan hang. | phần lớn tour trong khu vực có hoạt động tham quan hang động |

Cần biên tập:

- **Nội dung trên chỉ giới thiệu thông tin có trong trích đoạn được cung cấp.**: Phạm vi trích đoạn đã được nói trong nội dung, kết lại nhắc quy trình cung cấp dữ liệu. Gộp giới hạn nguồn vào ghi chú cuối ngắn; giữ cảnh báo riêng cho hạn mức200.

Bảo toàn: nguồn không có khối pre để so sánh. Nguồn có 1 metadata ảnh; chưa chấm ảnh hoặc regenerate.

Giới hạn và việc cần xác nhận:

- Ngày xuất bản/hạn mức hiện hành chưa được xác minh.
- Chỉ có tiêu đề Phong Nha and Paradise Caves, không có phần mô tả hoạt động; không yêu cầu AI tự hoàn thiện.
- Ảnh chỉ có URL/alt, chưa nhìn pixel.

### Q32

**Hội An** · [Nguồn](../../task2-quality/corpus-v2/Q32.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q32-C.json)

Điểm: **4.6/5 văn phong**, **8/8 đơn vị dữ kiện đề xuất giữ đúng**.

Mạch lịch sử giao thương tới trải nghiệm văn hóa tự nhiên, không tự viết đủ bảy hoạt động khi nguồn chưa nêu. Các danh hiệu và thế kỷ được giữ theo bài giới thiệu, chưa chứng minh đúng ngoài nguồn.

| Dẫn chứng nguồn | Dữ kiện và điều kiện cần giữ | Cụm đối chiếu trong bài AI |
| --- | --- | --- |
| S001 | Danh hiệu Asia's Leading Cultural City Destination2022 tạiWTA29 Asia/Oceania tháng9/2022. | Tháng 9 năm 2022 |
| S001 | Lần thứba, vượt đề cử Seoul/HànQuốc vàKyoto/NhậtBản. | lần thứ ba Hội An giành danh hiệu này |
| S002 | Hội An là di sản thế giớiUNESCO. | Hội An là di sản thế giới UNESCO |
| S002 | Thương cảng từ thế kỷII sauCN theo nguồn. | thương cảng từ thế kỷ II sau Công nguyên |
| S002 | Hưng thịnh thế kỷXVI, thương nhân từ toànthếgiới. | thời kỳ hưng thịnh ở thế kỷ XVI |
| S002 | Văn hóa bảnđịa hòa trộn ảnhhưởng châuÂu,TrungQuốc,NhậtBản. | những ảnh hưởng từ châu Âu, Trung Quốc, Nhật Bản |
| S003 | Có cảnhsắc,bãi biển,gầnđiểmđếnkhác; văn hóa gây ấntượng mạnh theo nguồn. | giá trị văn hóa là yếu tố để lại ấn tượng sâu sắc nhất |
| S003 | Gợi mở MỹSơn,bảo tàng,biểu diễn,thamquan. | Di tích Mỹ Sơn, các bảo tàng, những buổi biểu diễn trực tiếp |

Cần biên tập:

- **các chuyến tham quan**: Ý excursions vẫn giữ nhưng sắc thái authentic của nguồn được diễn đạt chung. Có thể làm rõ đây là các chuyến tìm hiểu văn hóa; tránh tự nêu một tour hoặc hoạt động cụ thể.

Bảo toàn: nguồn không có khối pre để so sánh. Nguồn có 1 metadata ảnh; chưa chấm ảnh hoặc regenerate.

Giới hạn và việc cần xác nhận:

- Nguồn kết bằng lời dẫn bảy hoạt động mà chưa có danh sách; không đủ để chấm bài hướng dẫn du lịch đầy đủ.
- Không kiểm tính đúng lịch sử/thứ hạng bên ngoài snapshot, hoặc pixel ảnh.

### Q33

**Bảng GitHub Actions** · [Nguồn](../../task2-quality/corpus-v2/Q33.json) · [Bài AI gốc](../../task2-quality/study-2026-10-05-corpus-v2/Q33-C.json)

Run C gốc bị AI_PROVIDER_TIMEOUT, final và candidate_for_review đều null. Không có bài để đối chiếu, không gán 0 hoặc 1/5 và không thay bằng bài chạy lại.


Giới hạn và việc cần xác nhận:

- Bảng GitHub Actions đã đọc, nhưng không có output C cùng study để chấm fidelity/table preservation.

## Phạm vi kết luận và lưu kết quả

- Dữ liệu có đủ điểm cho 19 bài, một ca thiếu bài. Trung bình/trung vị văn phong chỉ tính 19 bài; Q33 không bị biến thành 0 hoặc 1/5.
- Giữ nguyên corpus, outputs lịch sử, hai HTML/CSV người đọc, bundle manifest và acceptance report. Nghiệm thu hai người đọc và chủ dự án vẫn chờ.
- Không có baseline hoặc thử nghiệm temperature mới; chưa kết luận cải thiện so với một phiên khác, giảm thời gian sửa hoặc đủ điều kiện rollout.
- [Dữ liệu chi tiết JSON](review.json) ghi danh tính AI, nguồn/output hash, facts đề xuất, điểm và giới hạn. Đây là schema AI riêng, không nhập vào hai slot chấm người.
- Bộ nguồn/AI để đọc vẫn ở [phiếu đối chiếu](../review/reviewer-1.html). Người đọc chấm độc lập nên hoàn tất lượt riêng trước khi xem báo cáo này.

Bundle được đối chiếu: `988b08b2c84bd9726727cedd3ba935a39fb5fe6b511a119d266676c33f995036`.
