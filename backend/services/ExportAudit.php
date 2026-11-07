<?php

declare(strict_types=1);

namespace App\Services;

/** One audit row for every CSV export. The file body is never stored. */
final class ExportAudit
{
    /** @param array<string, scalar|null> $extra */
    public static function record(string $kind, string $filename, int $rowCount, array $extra = []): void
    {
        AuditLogger::record([
            'module' => 'system',
            'action' => 'export',
            'targetType' => 'export',
            'targetLabel' => mb_substr($filename, 0, 255),
            'details' => array_merge([
                'kind' => $kind,
                'filename' => mb_substr($filename, 0, 255),
                'rowCount' => max(0, $rowCount),
            ], $extra),
        ]);
    }
}
