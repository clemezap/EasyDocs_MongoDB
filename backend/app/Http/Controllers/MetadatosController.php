<?php

namespace App\Http\Controllers;

use Collator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Metadatos del archivo: propiedades opcionales que escribe el usuario
 * (autor, fecha de publicación, etiquetas, idioma...).
 *
 * - Se guardan en el subdocumento "metadatos" del archivo. Cada archivo tiene los
 *   suyos: un libro no tiene los mismos campos que una foto o una canción.
 * - Los valores se guardan con su tipo de BSON (texto, número, fecha, sí/no, lista),
 *   no todo como texto, para poder ordenar y filtrar por ellos.
 * - Los ve quien puede ver el archivo; los cambia el dueño o quien tiene permiso
 *   de escritura (la misma regla que renombrar).
 */
class MetadatosController extends Controller
{
    private const TIPOS = ['texto', 'numero', 'fecha', 'booleano', 'lista'];

    // También lo exige el $jsonSchema de la colección (maxProperties)
    private const MAXIMO = 50;

    public function ver(Request $request, string $archivoId): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);
        $archivo = $this->archivoAccesible($archivoId, $usuarioId);

        if (! $archivo) {
            return $this->noEncontrado('El archivo no existe o no tienes acceso.');
        }

        return response()->json([
            'metadatos' => $this->lista($archivo->metadatos),
            'editable' => $this->puedeEditar($archivo, $usuarioId),
        ]);
    }

    /**
     * Agrega, edita y elimina propiedades en una sola actualización:
     *   metadatos: [{clave, tipo, valor}, ...]  -> $set de "metadatos.<clave>"
     *   eliminar:  [clave, ...]                 -> $unset de "metadatos.<clave>"
     * Las propiedades que no se mencionan se quedan como están.
     */
    public function guardar(Request $request, string $archivoId): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);
        $archivo = $this->archivoAccesible($archivoId, $usuarioId);

        if (! $archivo || ! $this->puedeEditar($archivo, $usuarioId)) {
            return $this->noEncontrado('El archivo no existe o no tienes permiso para modificarlo.');
        }

        $request->validate([
            'metadatos' => ['present', 'array', 'max:'.self::MAXIMO],
            'metadatos.*.clave' => ['required', 'string'],
            'metadatos.*.tipo' => ['required', 'in:'.implode(',', self::TIPOS)],
            'eliminar' => ['array'],
            'eliminar.*' => ['string'],
        ], [
            'metadatos.max' => 'Un archivo puede tener como máximo '.self::MAXIMO.' propiedades.',
            'metadatos.*.clave.required' => 'Cada propiedad necesita un nombre.',
            'metadatos.*.tipo.in' => 'El tipo de una propiedad no es válido.',
        ]);

        $asignar = [];

        foreach ($request->input('metadatos') as $i => $propiedad) {
            $clave = trim($propiedad['clave']);

            if ($error = $this->errorClave($clave)) {
                return $this->errorValidacion("metadatos.$i.clave", $error);
            }

            if (array_key_exists($clave, $asignar)) {
                return $this->errorValidacion("metadatos.$i.clave", "La propiedad \"$clave\" está repetida.");
            }

            [$valor, $error] = $this->convertir($propiedad['tipo'], $propiedad['valor'] ?? null);

            if ($error) {
                return $this->errorValidacion("metadatos.$i.valor", "\"$clave\": $error");
            }

            $asignar[$clave] = $valor;
        }

        // Lo que se vuelve a asignar no se elimina (renombrar "Autor" a "autor" y viceversa)
        $eliminar = array_values(array_diff(
            array_map('trim', $request->input('eliminar', [])),
            array_keys($asignar)
        ));

        foreach ($eliminar as $clave) {
            if ($this->errorClave($clave)) {
                return $this->errorValidacion('eliminar', 'El nombre de una propiedad a eliminar no es válido.');
            }
        }

        $finales = array_diff(
            array_unique([...array_keys((array) $archivo->metadatos), ...array_keys($asignar)]),
            $eliminar
        );

        if (count($finales) > self::MAXIMO) {
            return $this->errorValidacion('metadatos', 'Un archivo puede tener como máximo '.self::MAXIMO.' propiedades.');
        }

        // Una sola actualización con $set y $unset sobre rutas "metadatos.<clave>"
        $cambios = ['$set' => ['actualizado_en' => $this->ahora()]];

        foreach ($asignar as $clave => $valor) {
            $cambios['$set']["metadatos.$clave"] = $valor;
        }

        foreach ($eliminar as $clave) {
            $cambios['$unset']["metadatos.$clave"] = '';
        }

        $this->base->archivos->updateOne(['_id' => $archivoId], $cambios);

        $archivo = $this->archivoAccesible($archivoId, $usuarioId);

        return response()->json([
            'metadatos' => $this->lista($archivo->metadatos),
            'editable' => true,
        ]);
    }

    /**
     * El nombre se usa dentro de la ruta "metadatos.<clave>": un "." escribiría en
     * un subdocumento y un "$" se confundiría con un operador. Por eso se rechazan.
     */
    private function errorClave(string $clave): ?string
    {
        if ($clave === '') {
            return 'Cada propiedad necesita un nombre.';
        }

        if (mb_strlen($clave) > 64) {
            return "El nombre \"$clave\" es demasiado largo (máximo 64 caracteres).";
        }

        if (str_contains($clave, '.') || str_contains($clave, '$')) {
            return "El nombre \"$clave\" no puede llevar punto (.) ni signo de pesos ($).";
        }

        return null;
    }

    /**
     * Valor que llega del formulario -> valor de BSON. Devuelve [valor, error].
     */
    private function convertir(string $tipo, mixed $valor): array
    {
        switch ($tipo) {
            case 'texto':
                $texto = is_scalar($valor) ? trim((string) $valor) : '';

                if ($texto === '') {
                    return [null, 'escribe un valor.'];
                }

                return mb_strlen($texto) > 1000
                    ? [null, 'el texto puede tener como máximo 1000 caracteres.']
                    : [$texto, null];

            case 'numero':
                if (! is_numeric($valor)) {
                    return [null, 'escribe un número.'];
                }

                // "471" se guarda como entero; "4.5" como decimal
                return [str_contains((string) $valor, '.') || str_contains(strtolower((string) $valor), 'e')
                    ? (float) $valor
                    : (int) $valor, null];

            case 'fecha':
                $fecha = is_string($valor)
                    ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor, new DateTimeZone('UTC'))
                    : false;

                if (! $fecha || $fecha->format('Y-m-d') !== $valor) {
                    return [null, 'elige una fecha válida.'];
                }

                return [new UTCDateTime($fecha), null];

            case 'booleano':
                return is_bool($valor) ? [$valor, null] : [null, 'elige sí o no.'];

            case 'lista':
                $elementos = is_array($valor) ? $valor : explode(',', is_string($valor) ? $valor : '');
                $elementos = array_values(array_unique(array_filter(
                    array_map(fn ($e) => is_scalar($e) ? trim((string) $e) : '', $elementos),
                    fn ($e) => $e !== ''
                )));

                if ($elementos === []) {
                    return [null, 'escribe al menos un elemento.'];
                }

                if (count($elementos) > 50 || max(array_map('mb_strlen', $elementos)) > 100) {
                    return [null, 'como máximo 50 elementos de 100 caracteres.'];
                }

                return [$elementos, null];
        }

        return [null, 'tipo no válido.'];
    }

    /**
     * Subdocumento -> [{clave, tipo, valor}] ordenado alfabéticamente.
     * El tipo se deduce del tipo de BSON con que quedó guardado el valor.
     *
     * Se ordena aquí porque MongoDB no conserva el orden en que se enviaron: cuando un
     * $set agrega varios campos nuevos, los escribe en orden lexicográfico de su nombre.
     */
    private function lista(object $metadatos): array
    {
        $lista = [];
        $orden = new Collator('es');

        foreach ((array) $metadatos as $clave => $valor) {
            // PHP convierte claves como "2024" en enteros
            $clave = (string) $clave;

            $lista[] = match (true) {
                $valor instanceof UTCDateTime => ['clave' => $clave, 'tipo' => 'fecha', 'valor' => $valor->toDateTime()->format('Y-m-d')],
                is_bool($valor) => ['clave' => $clave, 'tipo' => 'booleano', 'valor' => $valor],
                is_int($valor), is_float($valor) => ['clave' => $clave, 'tipo' => 'numero', 'valor' => $valor],
                is_array($valor) => ['clave' => $clave, 'tipo' => 'lista', 'valor' => $valor],
                default => ['clave' => $clave, 'tipo' => 'texto', 'valor' => (string) $valor],
            };
        }

        usort($lista, fn ($a, $b) => $orden->compare($a['clave'], $b['clave']));

        return $lista;
    }

    /**
     * Archivo activo que el usuario puede ver: es el dueño O se lo compartieron.
     */
    private function archivoAccesible(string $id, ObjectId $usuarioId): ?object
    {
        return $this->base->archivos->findOne(
            [
                '_id' => $id,
                'papelera' => false,
                '$or' => [
                    ['usuario_id' => $usuarioId],
                    ['compartidos.usuario_id' => $usuarioId],
                ],
            ],
            ['projection' => ['usuario_id' => 1, 'metadatos' => 1, 'compartidos' => 1]]
        );
    }

    private function puedeEditar(object $archivo, ObjectId $usuarioId): bool
    {
        if ((string) $archivo->usuario_id === (string) $usuarioId) {
            return true;
        }

        foreach ($archivo->compartidos as $compartido) {
            if ((string) $compartido->usuario_id === (string) $usuarioId) {
                return $compartido->permiso === 'escritura';
            }
        }

        return false;
    }
}
