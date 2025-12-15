<?php

namespace App\Services;

use App\Services\Support\CoverPathResolver;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SimpleXMLElement;
use ZipArchive;

class BookCatalog
{
    public function __construct(private CoverPathResolver $coverPathResolver)
    {
    }

    public function paginate(int $perPage, int $page, ?string $query = null): LengthAwarePaginator
    {
        $disk = Storage::disk('library');
        $files = $this->collectEpubFiles($disk, $query);

        $total = $files->count();
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $items = $files
            ->slice($offset, $perPage)
            ->map(fn (string $path): array => $this->mapFile($path, $disk))
            ->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => url()->current(),
                'pageName' => 'page',
            ]
        );
    }

    public function stats(): array
    {
        $disk = Storage::disk('library');
        $files = $this->collectEpubFiles($disk);

        $totalSize = 0;
        $latest = null;

        foreach ($files as $path) {
            $totalSize += $disk->size($path);
            $modified = $disk->lastModified($path);
            $latest = $latest === null ? $modified : max($latest, $modified);
        }

        return [
            'total_books' => $files->count(),
            'total_size_bytes' => $totalSize,
            'last_file_change' => $latest ? Carbon::createFromTimestamp($latest)->toIso8601String() : null,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function findByEncodedId(string $encoded): ?array
    {
        $path = $this->decodePath($encoded);

        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('library');

        if (! $disk->exists($path)) {
            return null;
        }

        return $this->mapFile($path, $disk);
    }

    /**
     * @return resource|false
     */
    public function stream(string $path)
    {
        return Storage::disk('library')->readStream($path);
    }

    /**
     * @return array{stream: resource, mime: string}|null
     */
    public function streamCover(string $path): ?array
    {
        $metadata = $this->metadata($path);
        $coverPath = $metadata['cover_path'] ?? null;
        $absolute = $this->absolutePath($path);
        $result = null;

        if ($coverPath && $absolute) {
            $stream = @fopen('zip://'.str_replace('\\', '/', $absolute).'#'.ltrim($coverPath, '/'), 'rb');

            if (is_resource($stream)) {
                $result = [
                    'stream' => $stream,
                    'mime' => $this->guessMimeType($coverPath),
                ];
            }
        }

        return $result;
    }

    private function collectEpubFiles(Filesystem $disk, ?string $query = null): Collection
    {
        $files = collect($disk->allFiles())
            ->filter(fn (string $path): bool => $this->isSupportedFile($path));

        if ($query) {
            $needle = Str::lower($query);
            $files = $files->filter(
                fn (string $path): bool => Str::contains(Str::lower(basename($path)), $needle)
            );
        }

        return $files->sort()->values();
    }

    private function mapFile(string $path, Filesystem $disk): array
    {
        $metadata = $this->metadata($path);

        return [
            'id' => $this->encodePath($path),
            'path' => $path,
            'file_name' => basename($path),
            'extension' => pathinfo($path, PATHINFO_EXTENSION),
            'file_size' => $disk->size($path),
            'last_modified' => $disk->lastModified($path),
            'title' => $metadata['title'] ?? pathinfo($path, PATHINFO_FILENAME),
            'author' => $metadata['author'] ?? null,
            'language' => $metadata['language'] ?? null,
            'cover_path' => $metadata['cover_path'] ?? null,
            'mime_type' => $this->guessMimeType($path),
        ];
    }

    private function metadata(string $path): array
    {
        $disk = Storage::disk('library');

        $cacheKey = sprintf(
            'book_meta:%s:%s:%s',
            sha1($path),
            (string) $disk->lastModified($path),
            (string) $disk->size($path)
        );

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($path): array {
            $absolute = $this->absolutePath($path);

            if (! $absolute) {
                return $this->defaultMetadata($path);
            }

            $metadata = $this->extractMetadata($absolute);

            return array_merge($this->defaultMetadata($path), $metadata);
        });
    }

    private function extractMetadata(string $absolutePath): array
    {
        $document = $this->loadOpfDocument($absolutePath);

        if (! $document) {
            return [];
        }

        /** @var SimpleXMLElement $opf */
        $opf = $document['xml'];
        $rootFile = $document['root'];

        $title = $this->firstXPathValue($opf, '//dc:title');
        $creator = $this->firstXPathValue($opf, '//dc:creator');
        $language = $this->firstXPathValue($opf, '//dc:language');
        $coverPath = $this->coverPathResolver->resolve($opf, $rootFile);

        return array_filter([
            'title' => $title,
            'author' => $creator,
            'language' => $language,
            'cover_path' => $coverPath,
        ], fn ($value) => $value !== null);
    }

    private function loadOpfDocument(string $absolutePath): ?array
    {
        $zip = new ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            return null;
        }

        $document = null;

        try {
            $container = $zip->getFromName('META-INF/container.xml');
            $containerXml = $container !== false ? $this->safeXml($container) : null;
            $rootFile = $containerXml ? (string) ($containerXml->rootfiles->rootfile['full-path'] ?? '') : '';

            if ($containerXml && $rootFile !== '') {
                $opfContent = $zip->getFromName($rootFile);
                $opfXml = $opfContent !== false ? $this->safeXml($opfContent) : null;

                if ($opfXml) {
                    $opfXml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
                    $opfXml->registerXPathNamespace('opf', 'http://www.idpf.org/2007/opf');
                    $document = [
                        'xml' => $opfXml,
                        'root' => $rootFile,
                    ];
                }
            }
        } finally {
            $zip->close();
        }

        return $document;
    }

    private function firstXPathValue(SimpleXMLElement $xml, string $expression): ?string
    {
        $nodes = $xml->xpath($expression);

        if ($nodes === false || count($nodes) === 0) {
            return null;
        }

        $value = trim((string) $nodes[0]);

        return $value === '' ? null : $value;
    }

    private function defaultMetadata(string $path): array
    {
        return [
            'title' => pathinfo($path, PATHINFO_FILENAME),
            'author' => null,
            'language' => null,
            'cover_path' => null,
        ];
    }

    private function safeXml(string $xml): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $parsed ?: null;
    }

    private function absolutePath(string $path): ?string
    {
        $disk = Storage::disk('library');

        if (! method_exists($disk, 'path')) {
            return null;
        }

        return $disk->path($path);
    }

    private function guessMimeType(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'epub' => 'application/epub+zip',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'jpeg', 'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function isSupportedFile(string $path): bool
    {
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['epub', 'pdf']);
    }

    private function encodePath(string $path): string
    {
        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    }

    private function decodePath(string $encoded): ?string
    {
        $value = strtr($encoded, '-_', '+/');
        $remainder = strlen($value) % 4;

        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? null : $decoded;
    }
}
