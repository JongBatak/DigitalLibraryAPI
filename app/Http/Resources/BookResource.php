<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class BookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'file_name' => $this->resource['file_name'],
            'path' => $this->resource['path'],
            'extension' => $this->resource['extension'],
            'size_bytes' => $this->resource['file_size'],
            'last_modified' => Carbon::createFromTimestamp($this->resource['last_modified'])->toIso8601String(),
            'title' => $this->resource['title'],
            'author' => $this->resource['author'],
            'language' => $this->resource['language'],
            'download_url' => route('books.download', ['book' => $this->resource['id']]),
            'cover_url' => $this->resource['cover_path'] ? route('books.cover', ['book' => $this->resource['id']]) : null,
        ];
    }
}
