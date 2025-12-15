<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookResource;
use App\Services\BookCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookController extends Controller
{
    public function __construct(private readonly BookCatalog $catalog)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $perPage = $validated['per_page'] ?? 50;
        $page = $validated['page'] ?? 1;
        $query = $validated['q'] ?? null;

        $books = $this->catalog->paginate($perPage, $page, $query);

        return BookResource::collection($books);
    }

    public function download(string $book): StreamedResponse
    {
        $file = $this->catalog->findByEncodedId($book);

        abort_if(is_null($file), 404, 'Book not found.');

        $stream = $this->catalog->stream($file['path']);

        abort_if($stream === false, 500, 'Unable to open the requested book.');

        return response()->streamDownload(
            function () use ($stream): void {
                while (ob_get_level()) {
                    ob_end_clean();
                }

                fpassthru($stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            $file['file_name'],
            [
                'Content-Type' => $file['mime_type'] ?? 'application/epub+zip',
            ]
        );
    }

    public function cover(string $book): StreamedResponse
    {
        $file = $this->catalog->findByEncodedId($book);

        abort_if(is_null($file), 404, 'Book not found.');

        $cover = $this->catalog->streamCover($file['path']);

        abort_if($cover === null, 404, 'Cover image not found.');

        return response()->stream(
            function () use ($cover): void {
                fpassthru($cover['stream']);

                if (is_resource($cover['stream'])) {
                    fclose($cover['stream']);
                }
            },
            200,
            [
                'Content-Type' => $cover['mime'],
                'Content-Disposition' => 'inline; filename="'.$file['file_name'].'.cover"',
            ]
        );
    }

    public function stats(): JsonResponse
    {
        return response()->json($this->catalog->stats());
    }
}
