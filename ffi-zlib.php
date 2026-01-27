<?php
/**
 * FFI: Compressão usando zlib nativa
 * 
 * Demonstra uso de ponteiros e buffers em FFI
 */

$ffi = FFI::cdef("
    int compress(void *dest, size_t *destLen, const void *source, size_t sourceLen);
    int uncompress(void *dest, size_t *destLen, const void *source, size_t sourceLen);
", "libz.so.1");

$dados = str_repeat("AAAA performance extrema BBBB ", 10000);

// Buffer de destino (precisa ser grande o suficiente)
$destSize = FFI::new("size_t");
$destSize->cdata = strlen($dados) + 100; // margem de segurança
$dest = FFI::new("char[" . $destSize->cdata . "]");

$start = microtime(true);

// Comprime
$resultado = $ffi->compress($dest, FFI::addr($destSize), $dados, strlen($dados));

$tempoFFI = microtime(true) - $start;

if ($resultado === 0) { // Z_OK
    echo "=== Compressão via FFI (zlib nativa) ===\n";
    echo "Dados originais: " . number_format(strlen($dados)) . " bytes\n";
    echo "Dados comprimidos: " . number_format($destSize->cdata) . " bytes\n";
    echo "Taxa de compressão: " . number_format((1 - $destSize->cdata / strlen($dados)) * 100, 1) . "%\n";
    echo "Tempo FFI: " . number_format($tempoFFI * 1000, 4) . " ms\n";
}

// Comparativo com função PHP nativa
$start = microtime(true);
$comprimidoPHP = gzcompress($dados);
$tempoPHP = microtime(true) - $start;

echo "\n=== Compressão via PHP (gzcompress) ===\n";
echo "Dados comprimidos: " . number_format(strlen($comprimidoPHP)) . " bytes\n";
echo "Tempo PHP: " . number_format($tempoPHP * 1000, 4) . " ms\n";