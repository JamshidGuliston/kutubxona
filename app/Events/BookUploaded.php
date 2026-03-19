<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookFile;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class BookUploaded
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Book     $book,
        public readonly BookFile $bookFile,
    ) {}
}
