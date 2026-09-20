<?php

namespace Packages\Kilkana\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Packages\Core\Support\CoreActiveInstanceSupport;

/**
 * Elige, por contexto, qué candidato de Hunspell corrige cada errata: Jev, de TypeSafe AI.
 *
 * Hunspell ordena por parecido de letras —para «desición» propone «sedición»— y no sabe si
 * «Adif» es un apellido. Jev recibe el párrafo con las erratas marcadas y una pregunta cerrada
 * por errata: los candidatos más dos salidas, dejarla como está o «ninguna encaja». **No genera
 * texto**: solo elige, y el servidor comprueba que lo elegido estaba entre lo enviado.
 *
 * Lo que sale de casa es el párrafo con erratas y el titular, nunca la pieza; lo que se guarda
 * es la decisión, nunca el texto; y si falla, tarda o está apagado, quien llama se queda con la
 * primera sugerencia de Hunspell. Medido en `kilkana.app/tests/jev-bench.mjs`: 97 % de acierto
 * en español frente al 29 % de Hunspell, 0 nombres propios tocados, p95 de 375 ms.
 */
class SnkKilkanaContextualSpellcheckService
{
    public const KEEP = 'dejar_como_esta';

    public const NONE = 'ninguna_encaja';

    /** Techos de la petición: lo que un párrafo de prensa puede traer, y nada de un volcado. */
    public const MAX_PARAGRAPHS = 12;

    public const MAX_PARAGRAPH_CHARS = 2000;

    public const MAX_TYPOS = 10;

    public const MAX_CANDIDATES = 6;

    public const MAX_WORD_CHARS = 40;

    public function __construct(private readonly CoreActiveInstanceSupport $instance) {}

    /**
     * Una petición a Jev por párrafo, todas a la vez, y solo para los párrafos sin decisión en
     * caché. Devuelve una decisión por errata; un párrafo que falló o no llegó a tiempo no
     * devuelve nada, y el panel se queda con Hunspell para él.
     *
     * @param  array<int, array{id: int, text: string, typos: array<int, array{mark: int, word: string, candidates: array<int, string>}>}>  $paragraphs
     * @return array<int, array{paragraph: int, mark: int, choice: ?string, keep: bool, confidence: float}>
     */
    public function choose(array $paragraphs, ?string $headline): array
    {
        $started = hrtime(true);
        $choices = [];
        $pending = [];
        foreach ($paragraphs as $paragraph) {
            $key = $this->cacheKey($paragraph, $headline);
            $cached = Cache::get($key);
            if (is_array($cached)) {
                array_push($choices, ...$cached);
            } else {
                $pending[$key] = $paragraph;
            }
        }

        $tokens = 0;
        $failed = 0;
        if ($pending !== []) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $key, array $paragraph) => $pool->as($key)
                    ->withToken((string) config('kilkana.kilkana.spellcheck.key'))
                    ->timeout((int) config('kilkana.kilkana.spellcheck.timeout'))
                    ->connectTimeout(2)
                    ->post((string) config('kilkana.kilkana.spellcheck.endpoint'), $this->request($paragraph, $headline)),
                array_keys($pending),
                $pending,
            ));

            foreach ($pending as $key => $paragraph) {
                $response = $responses[$key] ?? null;
                if (! $response instanceof Response || ! $response->ok()) {
                    $failed++;

                    continue;
                }
                $decided = $this->decisions($paragraph, $response->json());
                $tokens += (int) $response->json('usage.input_tokens', 0);
                Cache::put($key, $decided, now()->addDays(7));
                array_push($choices, ...$decided);
            }
        }

        // Cifras y nunca texto: es lo que permite recalibrar el umbral con uso real.
        Log::info('kilkana.spellcheck.contextual', [
            'paragraphs' => count($paragraphs),
            'cached' => count($paragraphs) - count($pending),
            'asked' => count($pending),
            'failed' => $failed,
            'typos' => array_sum(array_map(fn ($p) => count($p['typos']), $paragraphs)),
            'input_tokens' => $tokens,
            'ms' => (int) ((hrtime(true) - $started) / 1e6),
        ]);

        return $choices;
    }

    /**
     * El cuerpo para Jev: el párrafo marcado, el titular y una pregunta cerrada por errata.
     *
     * Las instrucciones dicen la condición exacta y los criterios cubren los casos límite, porque
     * el modelo lee literalmente. Las claves de opción son los propios candidatos —las tildes
     * valen, medido—, más las dos salidas: sin ellas elegiría un candidato para un apellido.
     *
     * @param  array{id: int, text: string, typos: array<int, array{mark: int, word: string, candidates: array<int, string>}>}  $paragraph
     * @return array<string, mixed>
     */
    public function request(array $paragraph, ?string $headline): array
    {
        $questions = [];
        foreach ($paragraph['typos'] as $typo) {
            $criteria = [];
            foreach ($typo['candidates'] as $candidate) {
                $criteria[$candidate] = "«{$candidate}»: es la palabra que el periodista quiso escribir en el lugar marcado; la frase queda correcta y con sentido.";
            }
            $criteria[self::KEEP] = 'La palabra marcada está bien como está y no debe cambiarse: es un nombre propio, un apellido, un topónimo, una marca, una sigla, un extranjerismo o un tecnicismo que el diccionario no conoce.';
            $criteria[self::NONE] = 'La palabra marcada está mal escrita, pero ninguna de las otras opciones es la que el periodista quiso escribir.';

            $questions["t{$typo['mark']}"] = [
                'type' => 'choice',
                'instructions' => "El texto de `parrafo` es de un periódico. La palabra entre [[{$typo['mark']}: y ]] es la única que se evalúa en esta pregunta; las demás marcas se ignoran. Elige la opción que indica qué debe ir escrito exactamente en ese lugar.",
                'criteria' => $criteria,
            ];
        }

        $state = ['parrafo' => $paragraph['text']];
        if ($headline !== null && $headline !== '') {
            $state['titular'] = $headline;
        }

        return [
            'model' => (string) config('kilkana.kilkana.spellcheck.model'),
            'state' => $state,
            'questions' => $questions,
        ];
    }

    /**
     * Lo que Jev contestó, comprobado: una elección que no estaba entre lo enviado se descarta,
     * porque el contenido de un párrafo puede desviar al modelo y la salida tiene que quedar
     * acotada a lo que propuso Hunspell.
     *
     * @param  array{id: int, text: string, typos: array<int, array{mark: int, word: string, candidates: array<int, string>}>}  $paragraph
     * @param  array<string, mixed>  $payload
     * @return array<int, array{paragraph: int, mark: int, choice: ?string, keep: bool, confidence: float}>
     */
    public function decisions(array $paragraph, array $payload): array
    {
        $decided = [];
        foreach ($paragraph['typos'] as $typo) {
            $answer = $payload['answers']["t{$typo['mark']}"] ?? null;
            $choice = is_array($answer) ? ($answer['choice'] ?? null) : null;
            if (! is_string($choice) || ! in_array($choice, [...$typo['candidates'], self::KEEP, self::NONE], true)) {
                continue;
            }
            $decided[] = [
                'paragraph' => $paragraph['id'],
                'mark' => $typo['mark'],
                'choice' => in_array($choice, [self::KEEP, self::NONE], true) ? null : $choice,
                'keep' => $choice === self::KEEP,
                'confidence' => round((float) ($answer['confidence'] ?? 0), 3),
            ];
        }

        return $decided;
    }

    /**
     * La clave lleva el inquilino a mano —el prefijo de la caché es de la instalación entera— y
     * un hash de todo lo que cambia la respuesta: texto marcado, candidatos, titular y modelo.
     * Del texto solo queda el hash.
     *
     * @param  array{id: int, text: string, typos: array<int, array{mark: int, word: string, candidates: array<int, string>}>}  $paragraph
     */
    private function cacheKey(array $paragraph, ?string $headline): string
    {
        $tenant = $this->instance->id() ?? 'unknown';
        $hash = hash('sha256', json_encode([
            config('kilkana.kilkana.spellcheck.model'),
            $headline,
            $paragraph['text'],
            array_map(fn ($t) => [$t['mark'], $t['candidates']], $paragraph['typos']),
        ], JSON_THROW_ON_ERROR));

        return "kilkana:spellcheck:{$tenant}:{$paragraph['id']}:{$hash}";
    }
}
