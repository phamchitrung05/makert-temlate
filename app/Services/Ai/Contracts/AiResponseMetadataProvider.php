<?php

namespace App\Services\Ai\Contracts;

/** Diagnostics are separate from generated fields and contain no raw response or credentials. */
interface AiResponseMetadataProvider
{
    public function responseMetadata(): array;
}
