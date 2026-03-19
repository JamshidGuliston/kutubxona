<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Services\StorageService;
use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ProcessBookFile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum execution time: 10 minutes (large PDF files).
     */
    public int $timeout = 600;

    /**
     * Retry up to 3 times on failure.
     */
    public int $tries = 3;

    /**
     * Backoff delays in seconds: 1 min, 5 min, 15 min.
     */
    public array $backoff = [60, 300, 900];

    public string $queue = 'file-processing';

    public function __construct(
        private readonly Book     $book,
        private readonly BookFile $bookFile,
    ) {}

    public function handle(StorageService $storageService): void
    {
        Log::info('Processing book file', [
            'book_id'   => $this->book->id,
            'file_id'   => $this->bookFile->id,
            'tenant_id' => $this->book->tenant_id,
        ]);

        $this->bookFile->markAsProcessing();

        $localPath = null;

        try {
            // Step 1: Download from S3 temp to local /tmp/
            $localPath = $this->downloadToLocal();

            // Step 2: Validate file integrity
            $this->validateFileIntegrity($localPath);

            // Step 3: Virus scan (if enabled)
            $this->scanForViruses($localPath);

            // Step 4: Extract metadata
            $metadata = $this->extractMetadata($localPath, $this->bookFile->file_type);

            // Step 5: Generate cover thumbnail
            $coverData = $this->generateCoverThumbnail($localPath, $this->bookFile->file_type);

            // Step 6: Optimize/process the file
            $processedPath = $this->processFile($localPath, $this->bookFile->file_type);

            // Step 7: Upload final file to S3
            $finalKey = $storageService->generateBookFilePath(
                $this->book->tenant_id,
                $this->book->id,
                'processed_' . Str::uuid() . '.' . $this->bookFile->file_type
            );

            $storageService->uploadLocalFile($processedPath, $finalKey, $this->bookFile->mime_type);

            // Step 8: Upload cover thumbnail if generated
            $thumbnailKey = null;
            if ($coverData !== null) {
                $thumbnailKey = $storageService->generateBookFilePath(
                    $this->book->tenant_id,
                    $this->book->id,
                    'cover_thumb.webp'
                );
                $storageService->uploadContent($coverData, $thumbnailKey, 'image/webp');
            }

            // Step 9: Update BookFile record
            $this->bookFile->markAsReady([
                'pages'        => $metadata['pages'] ?? null,
                'toc'          => $metadata['toc'] ?? [],
                'title'        => $metadata['title'] ?? null,
                'extracted_at' => now()->toIso8601String(),
            ]);

            $this->bookFile->update([
                's3_key'           => $finalKey,
                'checksum_md5'     => md5_file($processedPath),
                'checksum_sha256'  => hash_file('sha256', $processedPath),
                'file_size'        => filesize($processedPath),
            ]);

            // Step 10: Update Book record with extracted data
            $bookUpdate = ['status' => 'published'];
            if (!empty($metadata['pages'])) {
                $bookUpdate['pages'] = $metadata['pages'];
            }
            if ($thumbnailKey !== null) {
                $bookUpdate['cover_thumbnail'] = $thumbnailKey;
                if (empty($this->book->cover_image)) {
                    // Use first page as cover if no explicit cover was uploaded
                    $bookUpdate['cover_image'] = $thumbnailKey;
                }
            }
            $this->book->update($bookUpdate);

            // Step 11: Delete temp file from S3
            Storage::disk('s3')->delete($this->bookFile->s3_key);

            // Update tenant storage usage
            $this->book->tenant->incrementStorageUsed((int) filesize($processedPath));

            Log::info('Book file processed successfully', [
                'book_id'  => $this->book->id,
                'file_id'  => $this->bookFile->id,
                'pages'    => $metadata['pages'] ?? 'unknown',
                'final_key'=> $finalKey,
            ]);

        } catch (\Throwable $e) {
            Log::error('Book file processing failed', [
                'book_id'  => $this->book->id,
                'file_id'  => $this->bookFile->id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            $this->bookFile->markAsFailed($e->getMessage());
            $this->book->update(['status' => 'draft']);

            throw $e; // Allow queue to retry

        } finally {
            // Always clean up local temp files
            if ($localPath && file_exists($localPath)) {
                @unlink($localPath);
            }
        }
    }

    private function downloadToLocal(): string
    {
        $tempPath = sys_get_temp_dir() . '/' . Str::uuid() . '.' . $this->bookFile->file_type;

        $stream   = Storage::disk('s3')->readStream($this->bookFile->s3_key);
        file_put_contents($tempPath, stream_get_contents($stream));
        fclose($stream);

        if (!file_exists($tempPath) || filesize($tempPath) === 0) {
            throw new \RuntimeException('Failed to download file from S3 or file is empty.');
        }

        return $tempPath;
    }

    private function validateFileIntegrity(string $localPath): void
    {
        $actualSize = filesize($localPath);
        if ($actualSize !== $this->bookFile->file_size && $this->bookFile->file_size > 0) {
            // Allow 5% tolerance for S3 multipart upload size differences
            $tolerance = $this->bookFile->file_size * 0.05;
            if (abs($actualSize - $this->bookFile->file_size) > $tolerance) {
                throw new \RuntimeException(sprintf(
                    'File size mismatch: expected %d bytes, got %d bytes.',
                    $this->bookFile->file_size,
                    $actualSize
                ));
            }
        }

        // Validate MIME type by reading file header
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($localPath);

        $allowedMimes = [
            'application/pdf',
            'application/epub+zip',
            'application/x-mobipocket-ebook',
            'image/vnd.djvu',
            'text/plain',
        ];

        if (!in_array($detected, $allowedMimes, true)) {
            throw new \RuntimeException("Detected MIME type '{$detected}' is not allowed.");
        }
    }

    private function scanForViruses(string $localPath): void
    {
        if (!config('storage.virus_scan_enabled', false)) {
            return;
        }

        // ClamAV virus scan
        $command = sprintf('clamscan --no-summary %s 2>&1', escapeshellarg($localPath));
        $output  = shell_exec($command);

        if ($output !== null && str_contains($output, 'FOUND')) {
            throw new \RuntimeException("Virus detected in uploaded file. Upload rejected.");
        }
    }

    private function extractMetadata(string $localPath, string $fileType): array
    {
        $metadata = [];

        if ($fileType === 'pdf') {
            // Use pdfinfo (poppler-utils) to extract PDF metadata
            $output = shell_exec(sprintf('pdfinfo %s 2>/dev/null', escapeshellarg($localPath)));
            if ($output) {
                if (preg_match('/Pages:\s+(\d+)/', $output, $m)) {
                    $metadata['pages'] = (int) $m[1];
                }
                if (preg_match('/Title:\s+(.+)/', $output, $m)) {
                    $metadata['title'] = trim($m[1]);
                }
                if (preg_match('/Author:\s+(.+)/', $output, $m)) {
                    $metadata['author'] = trim($m[1]);
                }
            }
        } elseif ($fileType === 'epub') {
            // Extract EPUB metadata from OPF file
            $metadata = $this->extractEpubMetadata($localPath);
        }

        return $metadata;
    }

    private function extractEpubMetadata(string $epubPath): array
    {
        $metadata = [];

        try {
            $zip = new \ZipArchive();
            if ($zip->open($epubPath) !== true) {
                return $metadata;
            }

            // Read container.xml to find OPF file
            $containerXml = $zip->getFromName('META-INF/container.xml');
            if ($containerXml === false) {
                $zip->close();
                return $metadata;
            }

            $container  = simplexml_load_string($containerXml);
            $opfPath    = (string) ($container->rootfiles->rootfile['full-path'] ?? 'content.opf');
            $opfContent = $zip->getFromName($opfPath);
            $zip->close();

            if ($opfContent === false) {
                return $metadata;
            }

            $opf = simplexml_load_string($opfContent);
            $opf->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');

            $title  = $opf->xpath('//dc:title');
            $author = $opf->xpath('//dc:creator');

            if (!empty($title)) {
                $metadata['title'] = (string) $title[0];
            }
            if (!empty($author)) {
                $metadata['author'] = (string) $author[0];
            }
        } catch (\Throwable) {
            // Non-fatal: metadata extraction failure doesn't block processing
        }

        return $metadata;
    }

    private function generateCoverThumbnail(string $localPath, string $fileType): ?string
    {
        if ($fileType !== 'pdf') {
            return null;
        }

        try {
            // Use Imagick (or GhostScript) to extract first page
            if (!extension_loaded('imagick')) {
                return null;
            }

            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImage($localPath . '[0]'); // First page only
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality(85);

            // Resize to max 600px wide, maintain aspect ratio
            $imagick->thumbnailImage(600, 0);

            $webpData = $imagick->getImageBlob();
            $imagick->destroy();

            return $webpData;
        } catch (\Throwable $e) {
            Log::warning('Cover thumbnail generation failed', [
                'file'  => $localPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function processFile(string $localPath, string $fileType): string
    {
        if ($fileType !== 'pdf') {
            return $localPath; // No processing for non-PDF files currently
        }

        // Linearize PDF for web (fast first page) using qpdf
        $processedPath = sys_get_temp_dir() . '/' . Str::uuid() . '_processed.pdf';
        $command = sprintf(
            'qpdf --linearize %s %s 2>/dev/null',
            escapeshellarg($localPath),
            escapeshellarg($processedPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($processedPath)) {
            // qpdf not available or failed — use original file
            return $localPath;
        }

        return $processedPath;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessBookFile job permanently failed', [
            'book_id'   => $this->book->id,
            'file_id'   => $this->bookFile->id,
            'exception' => $exception->getMessage(),
        ]);

        $this->bookFile->markAsFailed('Max retries exceeded: ' . $exception->getMessage());
        $this->book->update(['status' => 'draft']);

        // Notify tenant admin
        \App\Jobs\SendNotification::dispatch(
            $this->book->tenant_id,
            'book_processing_failed',
            [
                'book_title' => $this->book->title,
                'error'      => $exception->getMessage(),
            ]
        );
    }
}
