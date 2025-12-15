<?php

namespace App\Services\Support;

use SimpleXMLElement;

class CoverPathResolver
{
    public function resolve(SimpleXMLElement $opfXml, string $rootFile): ?string
    {
        $coverId = $this->coverIdFromMeta($opfXml)
            ?? $this->coverIdFromManifest($opfXml);

        $href = $this->hrefFromManifest($opfXml, $coverId)
            ?? $this->firstImageHref($opfXml);

        return $href ? $this->normalizeHref($href, $rootFile) : null;
    }

    private function coverIdFromMeta(SimpleXMLElement $opfXml): ?string
    {
        foreach ($opfXml->metadata->meta ?? [] as $meta) {
            $name = strtolower((string) ($meta['name'] ?? ''));
            if ($name === 'cover') {
                $content = trim((string) ($meta['content'] ?? ''));
                if ($content !== '') {
                    return $content;
                }
            }
        }

        return null;
    }

    private function coverIdFromManifest(SimpleXMLElement $opfXml): ?string
    {
        foreach ($opfXml->manifest->item ?? [] as $item) {
            $properties = strtolower((string) ($item['properties'] ?? ''));
            if (str_contains($properties, 'cover-image')) {
                $id = trim((string) ($item['id'] ?? ''));
                if ($id !== '') {
                    return $id;
                }
            }
        }

        return null;
    }

    private function hrefFromManifest(SimpleXMLElement $opfXml, ?string $coverId): ?string
    {
        if ($coverId === null) {
            return null;
        }

        foreach ($opfXml->manifest->item ?? [] as $item) {
            if ((string) ($item['id'] ?? '') === $coverId) {
                $href = trim((string) ($item['href'] ?? ''));
                return $href !== '' ? $href : null;
            }
        }

        return null;
    }

    private function firstImageHref(SimpleXMLElement $opfXml): ?string
    {
        foreach ($opfXml->manifest->item ?? [] as $item) {
            $mediaType = strtolower((string) ($item['media-type'] ?? ''));
            if (str_contains($mediaType, 'image/')) {
                $href = trim((string) ($item['href'] ?? ''));
                if ($href !== '') {
                    return $href;
                }
            }
        }

        return null;
    }

    private function normalizeHref(string $href, string $rootFile): string
    {
        $directory = trim(str_replace('\\', '/', pathinfo($rootFile, PATHINFO_DIRNAME)), '/');

        if ($directory !== '' && $directory !== '.') {
            $href = $directory.'/'.ltrim($href, '/');
        }

        return str_replace('\\', '/', $href);
    }
}

