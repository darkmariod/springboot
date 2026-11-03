<?php

/**
 * Informe en español de las pruebas de las reuniones.
 *
 * Lee el resultado de PHPUnit (JUnit XML), los comentarios de cada prueba (quién pidió, qué se probó) y, si existe, el JSON de la
 * verificación de pantalla (Node). Imprime la tabla «Pedido | Reunión | Qué se probó | Resultado», un resumen por pedido, los
 * totales y una línea final: «LISTO PARA LA REUNIÓN» o «HAY PEDIDOS QUE FALLAN: R…».
 *
 * Uso:  php scripts/resumen-reuniones.php <junit.xml> [pantalla.json]
 * Código de salida: 0 si todo pasa (los PENDIENTES no cuentan como fallo), 1 si algún pedido falla o no tiene prueba.
 */

const TOTAL_PEDIDOS = 27;

$xmlRuta = $argv[1] ?? null;
$jsonRuta = $argv[2] ?? null;
$raiz = dirname(__DIR__);

$color = function_exists('posix_isatty') && @posix_isatty(STDOUT) && getenv('NO_COLOR') === false;
$pintar = fn (string $texto, string $codigo) => $color ? "\033[{$codigo}m{$texto}\033[0m" : $texto;

// ------------------------------------------------------------------ lo que dicen los comentarios de cada prueba

/** @return array<string,array{pedido_por:string,se_prueba:string,motivo_skip:string}> por «Clase::método» */
function leerComentarios(string $carpeta): array
{
    $datos = [];
    foreach (glob($carpeta.'/*.php') ?: [] as $archivo) {
        $codigo = (string) file_get_contents($archivo);
        if (! preg_match('/^namespace\s+([^;]+);/m', $codigo, $ns)) {
            continue;
        }
        preg_match_all('/\/\*\*((?:(?!\*\/).)*)\*\/\s*(?:#\[[^\]]*\]\s*)*public function (test_R\d+_\w+)/s', $codigo, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $hallazgo) {
            [$bloque, $metodo, $posicion] = [$hallazgo[1][0], $hallazgo[2][0], $hallazgo[2][1]];
            $lineas = array_map(fn ($l) => trim(preg_replace('/^\s*\*\s?/', '', $l)), explode("\n", $bloque));
            $pedidoPor = $seProbo = '';
            foreach ($lineas as $linea) {
                if (str_starts_with($linea, 'Pedido por:')) {
                    $pedidoPor = trim(substr($linea, strlen('Pedido por:')));
                } elseif (str_starts_with($linea, 'Se prueba:')) {
                    $seProbo = trim(substr($linea, strlen('Se prueba:')));
                }
            }
            // El resultado de PHPUnit (JUnit) no trae el motivo de un skip: se lee del propio método (markTestSkipped('PENDIENTE: …')).
            $cuerpo = substr($codigo, $posicion);
            $siguiente = strpos($cuerpo, 'public function ', 20);
            $cuerpo = $siguiente === false ? $cuerpo : substr($cuerpo, 0, $siguiente);
            $motivo = preg_match('/markTest(?:Skipped|Incomplete)\(\s*([\'"])(.*?)\1/s', $cuerpo, $sk) ? $sk[2] : '';
            $clase = trim($ns[1]).'\\'.pathinfo($archivo, PATHINFO_FILENAME);
            $datos[$clase.'::'.$metodo] = ['pedido_por' => $pedidoPor, 'se_prueba' => $seProbo, 'motivo_skip' => $motivo];
        }
    }

    return $datos;
}

/** «test_R6_el_estado_de_cuenta_muestra…» → «el estado de cuenta muestra…» */
function textoDelNombre(string $metodo): string
{
    return trim(str_replace('_', ' ', preg_replace('/^test_R\d+_/', '', $metodo)));
}

function claveDeOrden(string $pedido): int
{
    return (int) substr($pedido, 1);
}

// ------------------------------------------------------------------ resultados de PHPUnit

$filas = [];
if ($xmlRuta === null || ! is_file($xmlRuta)) {
    fwrite(STDERR, "No se encontró el resultado de las pruebas (JUnit XML). PHPUnit no llegó a ejecutarse.\n");
    echo $pintar("HAY PEDIDOS QUE FALLAN: no se pudieron ejecutar las pruebas del servidor\n", '1;31');
    exit(1);
}

$comentarios = leerComentarios($raiz.'/tests/Feature/Reuniones');
$xml = @simplexml_load_file($xmlRuta);
if ($xml === false) {
    fwrite(STDERR, "El resultado de las pruebas está dañado: {$xmlRuta}\n");
    echo $pintar("HAY PEDIDOS QUE FALLAN: el resultado de las pruebas está dañado\n", '1;31');
    exit(1);
}

foreach ($xml->xpath('//testcase') ?: [] as $caso) {
    $nombre = (string) $caso['name'];
    $clase = (string) $caso['class'];
    if (! preg_match('/^test_(R\d+)_/', $nombre, $m)) {
        continue;
    }
    $estado = 'OK';
    $detalle = '';
    if (isset($caso->failure) || isset($caso->error)) {
        $estado = 'FALLA';
        $detalle = trim((string) ($caso->failure ?? $caso->error));
    } elseif (isset($caso->skipped)) {
        $motivo = trim((string) $caso->skipped) ?: ($comentarios[$clase.'::'.$nombre]['motivo_skip'] ?? '');
        $estado = str_contains($motivo, 'PENDIENTE') ? 'PENDIENTE' : 'OMITIDA';
        $detalle = $motivo;
    }
    $c = $comentarios[$clase.'::'.$nombre] ?? ['pedido_por' => '', 'se_prueba' => ''];
    $filas[] = [
        'pedido' => $m[1],
        'reunion' => $c['pedido_por'] ?: '—',
        'texto' => $c['se_prueba'] ?: textoDelNombre($nombre),
        'estado' => $estado,
        'detalle' => $detalle,
        'origen' => 'servidor',
    ];
}

// ------------------------------------------------------------------ resultados de la pantalla (Node)

$errorPantalla = null;
if ($jsonRuta !== null) {
    $json = is_file($jsonRuta) ? json_decode((string) file_get_contents($jsonRuta), true) : null;
    if (! is_array($json) || ! isset($json['resultados'])) {
        $errorPantalla = 'La verificación de pantalla (Node) no entregó resultados.';
    } else {
        foreach ($json['resultados'] as $r) {
            $filas[] = [
                'pedido' => $r['pedido'],
                'reunion' => $r['reunion'] ?? '—',
                'texto' => $r['descripcion'],
                'estado' => $r['ok'] ? 'OK' : 'FALLA',
                'detalle' => $r['ok'] ? '' : (string) $r['detalle'],
                'origen' => 'pantalla',
            ];
        }
    }
}

usort($filas, fn ($a, $b) => [claveDeOrden($a['pedido']), $a['origen'] === 'pantalla' ? 1 : 0] <=> [claveDeOrden($b['pedido']), $b['origen'] === 'pantalla' ? 1 : 0]);

// ------------------------------------------------------------------ la tabla

function ajustar(string $texto, int $ancho): array
{
    $lineas = [];
    $actual = '';
    foreach (preg_split('/\s+/u', trim($texto)) ?: [] as $palabra) {
        if ($actual !== '' && mb_strlen($actual.' '.$palabra) > $ancho) {
            $lineas[] = $actual;
            $actual = $palabra;
        } else {
            $actual = $actual === '' ? $palabra : $actual.' '.$palabra;
        }
    }
    $lineas[] = $actual;

    return $lineas;
}

$anchos = [6, 23, 74, 10];
$cortar = fn () => '+'.implode('+', array_map(fn ($w) => str_repeat('-', $w + 2), $anchos)).'+';
$celda = fn (string $t, int $w) => ' '.mb_str_pad($t, $w).' ';

echo "\n".$pintar('PRUEBAS DE LAS REUNIONES — resultado', '1')."\n";
echo $cortar()."\n";
echo '|'.implode('|', [$celda('Pedido', $anchos[0]), $celda('Reunión', $anchos[1]), $celda('Qué se probó', $anchos[2]), $celda('Resultado', $anchos[3])])."|\n";
echo $cortar()."\n";
foreach ($filas as $f) {
    $reunion = ajustar($f['reunion'], $anchos[1]);
    $texto = ajustar(($f['origen'] === 'pantalla' ? '[pantalla] ' : '').$f['texto'], $anchos[2]);
    $alto = max(count($reunion), count($texto));
    $etiqueta = ['OK' => 'OK', 'FALLA' => 'FALLA', 'PENDIENTE' => 'PENDIENTE', 'OMITIDA' => 'OMITIDA'][$f['estado']];
    $codigo = ['OK' => '32', 'FALLA' => '1;31', 'PENDIENTE' => '33', 'OMITIDA' => '33'][$f['estado']];
    for ($i = 0; $i < $alto; $i++) {
        $res = $i === 0 ? $etiqueta : '';
        $resCelda = $color && $res !== '' ? ' '.$pintar(mb_str_pad($res, $anchos[3]), $codigo).' ' : $celda($res, $anchos[3]);
        echo '|'.implode('|', [
            $celda($i === 0 ? $f['pedido'] : '', $anchos[0]),
            $celda($reunion[$i] ?? '', $anchos[1]),
            $celda($texto[$i] ?? '', $anchos[2]),
            $resCelda,
        ])."|\n";
    }
}
echo $cortar()."\n";

// ------------------------------------------------------------------ por pedido y totales

$porPedido = [];
foreach ($filas as $f) {
    $p = &$porPedido[$f['pedido']];
    $p ??= ['OK' => 0, 'FALLA' => 0, 'PENDIENTE' => 0, 'OMITIDA' => 0];
    $p[$f['estado']]++;
    unset($p);
}
uksort($porPedido, fn ($a, $b) => claveDeOrden($a) <=> claveDeOrden($b));

$fallan = $pendientes = $sinPrueba = [];
echo "\n".$pintar('Resumen por pedido', '1')."\n";
for ($n = 1; $n <= TOTAL_PEDIDOS; $n++) {
    $id = 'R'.$n;
    $p = $porPedido[$id] ?? null;
    if ($p === null) {
        $sinPrueba[] = $id;
        echo sprintf("  %-4s %s\n", $id, $pintar('SIN PRUEBA', '1;31'));
        continue;
    }
    if ($p['FALLA'] > 0) {
        $fallan[] = $id;
        $estado = $pintar('FALLA', '1;31');
    } elseif ($p['PENDIENTE'] > 0) {
        $pendientes[] = $id;
        $estado = $pintar('PENDIENTE', '33');
    } elseif ($p['OMITIDA'] > 0 && $p['OK'] === 0) {
        $fallan[] = $id;
        $estado = $pintar('OMITIDA (sin comprobar)', '1;31');
    } else {
        $estado = $pintar('OK', '32');
    }
    echo sprintf("  %-4s %s  (%d de %d comprobaciones)\n", $id, $estado, $p['OK'], array_sum($p));
}

$total = count($filas);
$ok = count(array_filter($filas, fn ($f) => $f['estado'] === 'OK'));
$bad = count(array_filter($filas, fn ($f) => $f['estado'] === 'FALLA'));
$pend = count(array_filter($filas, fn ($f) => $f['estado'] === 'PENDIENTE'));
echo sprintf("\nTotales: %d comprobaciones · %d correctas · %d con falla · %d pendientes\n", $total, $ok, $bad, $pend);

// ------------------------------------------------------------------ detalle de lo que falla

foreach ($filas as $f) {
    if ($f['estado'] === 'FALLA') {
        $primera = strtok($f['detalle'], "\n") ?: $f['detalle'];
        echo "\n".$pintar("FALLA {$f['pedido']}", '1;31').': '.$f['texto']."\n  ".mb_substr($primera, 0, 400)."\n";
    } elseif ($f['estado'] === 'PENDIENTE') {
        echo "\n".$pintar("PENDIENTE {$f['pedido']}", '33').': '.$f['texto']."\n  ".mb_substr($f['detalle'], 0, 400)."\n";
    }
}
if ($errorPantalla !== null) {
    echo "\n".$pintar($errorPantalla, '1;31')."\n";
}

// ------------------------------------------------------------------ veredicto

$rotos = array_values(array_unique(array_merge($fallan, $sinPrueba)));
usort($rotos, fn ($a, $b) => claveDeOrden($a) <=> claveDeOrden($b));
echo "\n";
if ($rotos || $errorPantalla !== null) {
    $lista = $rotos ? implode(', ', $rotos) : 'verificación de pantalla';
    echo $pintar("HAY PEDIDOS QUE FALLAN: {$lista}", '1;31')."\n";
    exit(1);
}
echo $pintar('LISTO PARA LA REUNIÓN', '1;32')."\n";
if ($pendientes) {
    echo $pintar('Atención: quedan pedidos PENDIENTES (hallazgos): '.implode(', ', $pendientes), '33')."\n";
}
exit(0);
