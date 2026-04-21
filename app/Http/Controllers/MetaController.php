<?php

namespace App\Http\Controllers;

use App\Models\BibleVersion;
use App\Models\BibleVerse;

class MetaController extends Controller
{
    public function __construct() {}

    public function index()
    {
        $data = [
            "bible" => $this->getBibleMeta()
        ];

        return response()->json($data);
    }

    private function getBibleMeta()
    {
        $total_versions = BibleVersion::count();

        $missing_verses = BibleVerse::query()
            ->join('bible_book', 'bible_book.id_bible_book', '=', 'bible_verse.id_bible_book')
            ->select(
                'bible_book.book_number',
                'bible_verse.chapter',
                'bible_verse.verse'
            )
            ->selectRaw('GROUP_CONCAT(DISTINCT bible_verse.id_bible_version) as versions')
            ->selectRaw('COUNT(DISTINCT bible_verse.id_bible_version) as total_versions')
            ->groupBy(
                'bible_book.book_number',
                'bible_verse.chapter',
                'bible_verse.verse'
            )
            ->having('total_versions', '<>', $total_versions)
            ->get()
            ->map(function ($item) {
                $item->versions = array_map('intval', explode(',', $item->versions));
                return $item;
            });

        $bible_version = BibleVersion::select(["id_bible_version", "name", "id_language"])
            ->withCount('verses')
            ->get()
            ->map(function ($version) use ($missing_verses) {

                $filtered = $missing_verses->filter(function ($verse) use ($version) {
                    return in_array($version->id_bible_version, $verse->versions);
                })->values();

                $query = BibleVerse::select(['id_bible_book', 'chapter', 'verse', 'text'])
                    ->with('book:id_bible_book,name,book_number');
                foreach ($filtered as $verse) {
                    $query->orWhere(function ($q) use ($version, $verse) {
                        $q->whereHas('book', function ($b) use ($verse) {
                            $b->where('book_number', $verse->book_number);
                        })
                            ->where('chapter', $verse->chapter)
                            ->where('verse', $verse->verse)
                            ->where('id_bible_version', $version->id_bible_version)
                            ->where('id_language', $version->id_language);
                    });
                }

                $verses = $query->get();

                $version->missing_verses = $verses;
                return $version;
            });

        return [
            "total_versions" => $total_versions,
            "versions" => $bible_version,
            "missing_verses" => $missing_verses,
        ];
    }
}
