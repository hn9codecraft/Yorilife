<?php

declare(strict_types=1);

namespace App\Services;

/** Small formatting helpers shared by the business-module presenters. */
final class Presenter
{
    /** UTC DATETIME → ISO-8601 with "Z" (null stays null). */
    public static function iso(?string $utcDateTime): ?string
    {
        return $utcDateTime === null ? null : str_replace(' ', 'T', $utcDateTime) . 'Z';
    }

    /** Ids leave the API as strings (the web app compares them as strings). */
    public static function id(int|string|null $id): ?string
    {
        return $id === null ? null : (string) $id;
    }
}
