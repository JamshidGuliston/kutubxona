<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Services\StorageService;
use App\Domain\AudioBook\Models\AudioBook;
use App\Domain\AudioBook\Models\AudioBookChapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ProcessAudioBook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;
    public int $tries   = 3;
    public array $backoff = [60, 300, 900];
    public string $queue = 'file-processing';

    public function __construct(
        private readonly AudioBook        $audioBook,
        private readonly AudioBookChapter $chapter,
    ) {}

    public function handle(StorageService $storageService): void
    {
        Log::info('Processing audiobook chapter', [
            'audiobook_id' => $this->audioBook->id,
            'chapter_id'   => $this->chapter->id,
            'chapter_num'  => $this->chapter->chapter_number,
            'tenant_id'    => $this->audioBook->tenant_id,
        ]);

        $this->chapter->update(['processing_status' => 'processing']);
        $localPath = null;
        $processedPath = null;

        try {
            // Step 1: Download from S3 temp to local
            $localPath = $this->downloadToLocal();

            // Step 2: Validate audio file
            $this->validateAudioFile($localPath);

            // Step 3: Extract audio metadata using ffprobe
            $audioMetadata = $this->extractAudioMetadata($localPath);

            // Step 4: Normalize audio (optional, if ffmpeg available)
            $processedPath = $this->normalizeAudio($localPath);

            // Step 5: Generate waveform data
            $waveformData = $this->generateWaveform($localPath);

            // Step 6: Upload to final S3 location
            $extension = pathinfo($this->chapter->s3_key, PATHINFO_EXTENSION);
            $finalKey  = $storageService->generateAudioPath(
                $this->audioBook->tenant_id,
                $this->audioBook->id,
                sprintf('chapter_%02d_%s.%s', $this->chapter->chapter_number, Str::uuid(), $extension)
            );

            $storageService->uploadLocalFile($processedPath ?? $localPath, $finalKey, $this->chapter->mime_type);

            // Step 7: Update chapter record
            $this->chapter->update([
                's3_key'            => $finalKey,
                'duration'          => $audioMetadata['duration'] ?? null,
                'file_size'         => filesize($processedPath ?? $localPath),
                'waveform_data'     => $waveformData,
                'processing_status' => 'ready',
            ]);

            // Step 8: Delete temp file
            Storage::disk('s3')->delete($this->chapter->s3_key);

            // Step 9: Update audiobook totals
            $this->audioBook->update([
                'total_chapters' => $this->audioBook->chapters()->where('processing_status', 'ready')->count(),
                'total_duration' => (int) $this->audioBook->chapters()->where('processing_status', 'ready')->sum('duration'),
            ]);

            // Update tenant storage usage
            $fileSize = filesize($processedPath ?? $localPath);
            $this->audioBook->tenant->incrementStorageUsed($fileSize);

            Log::info('Audiobook chapter processed successfully', [
                'chapter_id' => $this->chapter->id,
                'duration'   => $audioMetadata['duration'] ?? 0,
                'final_key'  => $finalKey,
            ]);

        } catch (\Throwable $e) {
            Log::error('Audiobook chapter processing failed', [
                'chapter_id' => $this->chapter->id,
                'error'      => $e->getMessage(),
            ]);

            $this->chapter->markAsFailed($e->getMessage());

            throw $e;

        } finally {
            if ($localPath && file_exists($localPath)) {
                @unlink($localPath);
            }
            if ($processedPath && $processedPath !== $localPath && file_exists($processedPath)) {
                @unlink($processedPath);
            }
        }
    }

    private function downloadToLocal(): string
    {
        $ext      = pathinfo($this->chapter->s3_key, PATHINFO_EXTENSION);
        $tempPath = sys_get_temp_dir() . '/' . Str::uuid() . '.' . $ext;
        $stream   = Storage::disk('s3')->readStream($this->chapter->s3_key);

        file_put_contents($tempPath, stream_get_contents($stream));
        fclose($stream);

        if (!file_exists($tempPath) || filesize($tempPath) === 0) {
            throw new \RuntimeException('Failed to download audio file from S3 or file is empty.');
        }

        return $tempPath;
    }

    private function validateAudioFile(string $localPath): void
    {
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($localPath);

        $allowedMimes = ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/aac', 'audio/x-m4a'];

        if (!in_array($detected, $allowedMimes, true)) {
            throw new \RuntimeException("Invalid audio MIME type: '{$detected}'.");
        }
    }

    private function extractAudioMetadata(string $localPath): array
    {
        $metadata = [];

        // Use ffprobe to get audio metadata
        $command = sprintf(
            'ffprobe -v quiet -print_format json -show_format -show_streams %s 2>/dev/null',
            escapeshellarg($localPath)
        );

        $output = shell_exec($command);
        if ($output) {
            $data = json_decode($output, true);
            $metadata['duration'] = (int) round((float) ($data['format']['duration'] ?? 0));
            $metadata['bitrate']  = (int) ($data['format']['bit_rate'] ?? 0);
            $metadata['format']   = $data['format']['format_name'] ?? null;
        }

        return $metadata;
    }

    private function normalizeAudio(string $localPath): ?string
    {
        // Check if ffmpeg is available
        exec('which ffmpeg 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return null; // ffmpeg not available
        }

        $processedPath = sys_get_temp_dir() . '/' . Str::uuid() . '_normalized.mp3';

        // Normalize to -14 LUFS (podcast standard), convert to 128k MP3
        $command = sprintf(
            'ffmpeg -i %s -af "loudnorm=I=-14:LRA=11:TP=-1.5" -ar 44100 -ab 128k %s -y 2>/dev/null',
            escapeshellarg($localPath),
            escapeshellarg($processedPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($processedPath)) {
            return null;
        }

        return $processedPath;
    }

    private function generateWaveform(string $localPath): ?array
    {
        // Check if audiowaveform is available
        exec('which audiowaveform 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return null; // audiowaveform not available
        }

        $waveformPath = sys_get_temp_dir() . '/' . Str::uuid() . '.json';

        $command = sprintf(
            'audiowaveform -i %s -o %s --pixels-per-second 10 --bits 8 2>/dev/null',
            escapeshellarg($localPath),
            escapeshellarg($waveformPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($waveformPath)) {
            return null;
        }

        $data = json_decode(file_get_contents($waveformPath), true);
        @unlink($waveformPath);

        // Return only the waveform data points (max 1000 points for frontend)
        $points = $data['data'] ?? [];
        if (count($points) > 1000) {
            $step   = (int) ceil(count($points) / 1000);
            $points = array_filter(
                $points,
                fn ($k) => $k % $step === 0,
                ARRAY_FILTER_USE_KEY
            );
        }

        return array_values($points);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessAudioBook job permanently failed', [
            'chapter_id' => $this->chapter->id,
            'exception'  => $exception->getMessage(),
        ]);

        $this->chapter->update(['processing_status' => 'failed']);
    }
}
