<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SplFileInfo;
use App\Models\Book;

/**
 * Import PDF (and EPUB) files from a local folder into the application.
 *
 * Behavior:
 * - Scans the provided directory recursively for files with extension .pdf or .epub
 * - By default, copies each file into the configured 'library' disk under "pdfs/YYYYmmdd/<random>_<name>"
 * - Attempts to insert a record into a `books` table (or use App\Models\Book if it exists).
 *
 * Usage:
 *  php artisan import:pdfs "C:\path\to\folder"
 *  php artisan import:pdfs "/home/user/my-pdfs" --copy=false
 */
class ImportPdfs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:pdfs
                            {path : Absolute or relative path to a directory containing PDFs/EPUBs}
                            {--copy=true : Copy files into the configured "library" disk (set to false to only register original paths)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import PDF/EPUB files from a folder into the library (and optionally into the database).';

    public function handle(): int
    {
        $inputPath = $this->argument('path');
        $copyOption = $this->option('copy');
        $copy = filter_var($copyOption, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($copy === null) {
            // allow "false" / "0" / "no" etc.
            $copy = strtolower((string) $copyOption) !== 'false' && (string) $copyOption !== '0';
        }

        $path = realpath($inputPath) ?: $inputPath;

        if (!is_dir($path)) {
            $this->error("Path not found or not a directory: {$inputPath}");
            return 1;
        }

        $this->info("Scanning directory: {$path}");
        $this->info('Copy to library disk: ' . ($copy ? 'yes' : 'no'));

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        $imported = 0;
        $skipped = 0;

        foreach ($rii as $file) {
            /** @var SplFileInfo $file */
            if ($file->isDir()) {
                continue;
            }

            $ext = strtolower($file->getExtension());
            if (! in_array($ext, ['pdf', 'epub'], true)) {
                continue;
            }

            $fullPath = $file->getRealPath();
            if (! $fullPath || ! is_readable($fullPath)) {
                $this->warn("Cannot read file: {$file->getPathname()}");
                $skipped++;
                continue;
            }

            $filename = $file->getBasename();
            $filesize = $file->getSize();
            $mime = $this->guessMimeType($filename);

            // Use a clean title derived from filename
            $title = pathinfo($filename, PATHINFO_FILENAME);
            $title = preg_replace('/[_\-]+/', ' ', $title);
            $title = trim($title);

            // Destination path on storage disk
            $storagePath = "books/" . date('Ymd') . '/' . Str::random(8) . '_' . $filename;

            $disk = Storage::disk('library');

            if ($copy) {
                // copy using stream so large files don't exhaust memory
                $stream = @fopen($fullPath, 'r');
                if ($stream === false) {
                    $this->warn("Failed to open for reading: {$fullPath}");
                    $skipped++;
                    continue;
                }

                $written = $disk->put($storagePath, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                if ($written === false) {
                    $this->warn("Failed to write to library disk: {$storagePath}");
                    $skipped++;
                    continue;
                }
            } else {
                // Register original absolute path (not copied). We still store the absolute path in the record's path column.
                $storagePath = $fullPath;
            }

            // try to get page count via pdfinfo (optional)
            $pages = null;
            if ($ext === 'pdf' && function_exists('exec')) {
                $out = null;
                $ret = null;
                @exec('pdfinfo ' . escapeshellarg($fullPath) . ' 2>&1', $out, $ret);
                if ($ret === 0 && is_array($out)) {
                    foreach ($out as $line) {
                        if (preg_match('/^Pages:\s+(\d+)/i', $line, $m)) {
                            $pages = (int) $m[1];
                            break;
                        }
                    }
                }
            }

            // build a public URL when file is stored on a public disk (best-effort)
            $url = null;
            try {
                if ($copy && method_exists($disk, 'url')) {
                    // some disks support url() and will generate a usable link
                    $url = $disk->url($storagePath);
                }
            } catch (\Throwable $e) {
                // ignore: not all disks implement url() or configuration may be missing
                $url = null;
            }

            // Prepare record payload
            $record = [
                'title' => $title,
                'filename' => $filename,
                'path' => $storagePath,
                'url' => $url,
                'mime_type' => $mime,
                'size' => $filesize,
                'pages' => $pages,
                'author' => null,
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $createdId = null;

            // Attempt to persist record to DB if possible
            try {
                // Prefer Eloquent model if present
                if (class_exists(\App\Models\Book::class)) {
                    // avoid duplicate by filename + size
                    $bookModel = \App\Models\Book::firstOrCreate(
                        ['filename' => $filename, 'size' => $filesize],
                        $record
                    );
                    $createdId = $bookModel->id ?? null;
                } elseif (Schema::hasTable('books')) {
                    // Use query builder; avoid duplicates
                    $exists = DB::table('books')
                        ->where('filename', $filename)
                        ->where('size', $filesize)
                        ->first();

                    if ($exists) {
                        $this->line("Skipping (already registered): {$filename}");
                        $skipped++;
                        continue;
                    }

                    $createdId = DB::table('books')->insertGetId($record);
                } else {
                    // No DB persistence available — just log the import
                    $createdId = null;
                }
            } catch (\Throwable $e) {
                $this->warn("Failed to persist DB record for {$filename}: " . $e->getMessage());
                // continue — file may still have been copied
            }

            $imported++;
            $this->line("Imported: {$filename}" . ($createdId ? " (id: {$createdId})" : ''));
        }

        $this->info("Import complete. Imported: {$imported}. Skipped: {$skipped}.");

        return 0;
    }

    private function guessMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'epub' => 'application/epub+zip',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }
}
