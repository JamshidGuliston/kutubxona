<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BookUploaded;
use App\Jobs\ProcessBookFile;
use Illuminate\Support\Facades\Log;

final class ProcessBookAfterUpload
{
    public function handle(BookUploaded $event): void
    {
        Log::info('Dispatching book file processing job', [
            'book_id'  => $event->book->id,
            'file_id'  => $event->bookFile->id,
            'tenant_id'=> $event->book->tenant_id,
        ]);

        // Dispatch to 'file-processing' queue with high priority
        ProcessBookFile::dispatch($event->book, $event->bookFile)
            ->onQueue('file-processing');
    }
}
