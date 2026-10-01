<?php

namespace App\Services\Ai;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Connection theo một run, giữ secret trong memory của backend.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: __construct(), __debugInfo().
 * INPUT: snapshot public + key hiện tại.
 * OUTPUT: immutable connection; không serialize DTO vào queue, DB hoặc API.
 * SIDE EFFECT: Không ghi database hoặc log secret.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
final readonly class AiConnection
{
    public function __construct(
        public array $snapshot,
        #[\SensitiveParameter] public string $apiKey,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Redact API key khi debug object
     * =====================================================================
     * INPUT: debug dump.
     * OUTPUT: snapshot và placeholder [REDACTED], không có secret thật.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function __debugInfo(): array
    {
        return ['snapshot' => $this->snapshot, 'apiKey' => '[REDACTED]'];
    }
}
