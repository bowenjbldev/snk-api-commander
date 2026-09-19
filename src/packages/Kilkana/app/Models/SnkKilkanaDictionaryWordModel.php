<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una palabra que la redacción ha dado por buena para el corrector del editor.
 *
 * Es lo que un corrector de navegador no da: el nombre propio, el topónimo o la sigla que un
 * redactor añade una vez y deja de marcarse para todos. Es del periódico entero, no de quien la
 * añadió.
 */
class SnkKilkanaDictionaryWordModel extends Model
{
    protected $table = 'kilkana_dictionary_words';

    protected $guarded = [];

    /** De aquí salen el ancho de la columna y el tope de la validación. La palabra más larga del DRAE tiene 23 letras. */
    public const WORD_MAX = 64;
}
