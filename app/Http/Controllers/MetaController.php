<?php

namespace App\Http\Controllers;

use App\Models\BibleVersion;
use App\Models\BibleVerse;
use OpenApi\Attributes as OA;

class MetaController extends Controller
{
    public function __construct() {}

    #[OA\Get(
        path: '/metadata',
        operationId: 'getBibleMetadata',
        tags: ['Public'],
        security: [],
        summary: 'Metadados da Bíblia',
        description: 'Retorna estatísticas e versículos ausentes por versão bíblica'
    )]
    #[OA\Response(
        response: 200,
        description: 'Sucesso',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'bible',
                    properties: [
                        new OA\Property(property: 'total_versions', type: 'integer'),
                        new OA\Property(property: 'versions', type: 'array', items: new OA\Object),
                        new OA\Property(property: 'missing_verses', type: 'array', items: new OA\Object)
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 500,
        description: 'Erro interno do servidor'
    )]
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
        ->orderBy('bible_book.book_number')
        ->orderBy('bible_verse.chapter')
        ->orderBy('bible_verse.verse')
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
