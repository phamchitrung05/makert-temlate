/**
 * CASL đang được tạm hoãn ở Vue nên module này không export plugin mặc định.
 * Trình tự động đăng ký plugin sẽ bỏ qua file, còn source và dependency CASL
 * vẫn được giữ lại để có thể triển khai authorization UI trong giai đoạn sau.
 */
export const isCaslDeferred = true
