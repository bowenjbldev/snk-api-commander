<?php

namespace Packages\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Kilkana\Services\SnkKilkanaContextualSpellcheckService;
use Packages\Kilkana\Services\SnkKilkanaSettingService;

#[Group('Spellcheck')]
class SnkKilkanaContextualSpellcheckController extends Controller
{
    public function __construct(
        private readonly SnkKilkanaContextualSpellcheckService $contextual,
        private readonly SnkKilkanaSettingService $settings,
    ) {}

    /**
     * Qué candidato corrige cada errata de estos párrafos, según su contexto.
     *
     * El servidor no se fía del panel: techos de párrafos, de erratas y de candidatos, largo
     * máximo de cada palabra, y las marcas de cada errata tienen que estar en su párrafo. Con el
     * corrector contextual apagado para el periódico, 409: el panel no debería haber llamado.
     */
    public function choose(Request $request): JsonResponse
    {
        if (! $this->settings->get()->contextual_spellcheck) {
            return response()->json(['error' => 'Contextual spellcheck is off for this newspaper'], 409);
        }

        $s = SnkKilkanaContextualSpellcheckService::class;
        $data = $request->validate([
            'headline' => ['nullable', 'string', 'max:255'],
            'paragraphs' => ['required', 'array', 'min:1', 'max:'.$s::MAX_PARAGRAPHS],
            'paragraphs.*.id' => ['required', 'integer', 'min:0'],
            'paragraphs.*.text' => ['required', 'string', 'max:'.$s::MAX_PARAGRAPH_CHARS],
            'paragraphs.*.typos' => ['required', 'array', 'min:1', 'max:'.$s::MAX_TYPOS],
            'paragraphs.*.typos.*.mark' => ['required', 'integer', 'min:1', 'max:'.$s::MAX_TYPOS],
            'paragraphs.*.typos.*.word' => ['required', 'string', 'max:'.$s::MAX_WORD_CHARS],
            'paragraphs.*.typos.*.candidates' => ['required', 'array', 'min:1', 'max:'.$s::MAX_CANDIDATES],
            'paragraphs.*.typos.*.candidates.*' => ['required', 'string', 'max:'.$s::MAX_WORD_CHARS],
        ]);

        foreach ($data['paragraphs'] as $paragraph) {
            foreach ($paragraph['typos'] as $typo) {
                if (! str_contains($paragraph['text'], "[[{$typo['mark']}:{$typo['word']}]]")) {
                    return response()->json(['error' => "Mark {$typo['mark']} is not in its paragraph"], 422);
                }
            }
        }

        return response()->json(['choices' => $this->contextual->choose($data['paragraphs'], $data['headline'] ?? null)]);
    }
}
