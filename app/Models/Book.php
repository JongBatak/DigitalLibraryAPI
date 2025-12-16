<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * App\Models\Book
 *
 * @property int $id
 * @property string $title
 * @property string $filename
 * @property string $path
 * @property string|null $url
 * @property string|null $mime_type
 * @property int|null $size
 * @property int|null $pages
 * @property string|null $author
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Book extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int,string>
     */
    protected $fillable = [
        'title',
        'filename',
        'path',
        'url',
        'mime_type',
        'size',
        'pages',
        'author',
        'description',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'size' => 'integer',
        'pages' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Return a publicly accessible URL for the file when possible.
     *
     * If the `url` column is populated it will be returned. Otherwise,
     * attempt to resolve a URL from the configured `library` disk.
     *
     * @return string|null
     */
    public function getPublicUrlAttribute(): ?string
    {
        if (! empty($this->url)) {
            return $this->url;
        }

        // If path looks like an absolute filesystem path, we can't generate a URL.
        if (str_starts_with($this->path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $this->path)) {
            return null;
        }

        try {
            if (Storage::disk('library')->exists($this->path) && method_exists(Storage::disk('library'), 'url')) {
                return Storage::disk('library')->url($this->path);
            }
        } catch (\Throwable $e) {
            // ignore and return null
        }

        return null;
    }
}
