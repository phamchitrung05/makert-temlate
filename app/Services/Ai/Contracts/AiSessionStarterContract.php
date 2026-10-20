<?php

namespace App\Services\Ai\Contracts;

use App\Models\User;

/** Routes a validated AI Content request into a domain-owned asynchronous workflow. */
interface AiSessionStarterContract
{
    /** Return public job identities and the destination resource; never call a provider inline. */
    public function start(User $actor, array $data): array;
}
