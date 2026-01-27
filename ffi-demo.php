<?php
/**
 * FFI com biblioteca Rust customizada
 */

// Caminho para a biblioteca compilada
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $libPath = __DIR__ . '/ffi-rust/target/release/ffi_demo.dll';
} else {
    $libPath = __DIR__ . '/ffi-rust/target/release/libffi_demo.so';
}

if (!file_exists($libPath)) {
    die("Compile a biblioteca Rust primeiro: cargo build --release\n");
}

$ffi = FFI::cdef("
    uint64_t hash_djb2(const char *input);
    uint64_t fibonacci(uint32_t n);
    int64_t soma_array(const int64_t *arr, size_t len);
", $libPath);

echo "=== FFI com Biblioteca Rust ===\n\n";

// Teste 1: Hash
$texto = "Performance PHP com Rust!";
$hash = $ffi->hash_djb2($texto);
echo "Hash DJB2 de '$texto':\n";
echo "  Resultado: $hash\n\n";

// Teste 2: Fibonacci
echo "Fibonacci(50):\n";

$start = microtime(true);
$fibRust = $ffi->fibonacci(50);
$tempoRust = microtime(true) - $start;

echo "  Via Rust FFI: $fibRust\n";
echo "  Tempo: " . number_format($tempoRust * 1000000, 2) . " μs\n\n";

// Comparativo com PHP puro
function fibonacciPHP(int $n): int {
    if ($n <= 1) return $n;
    $a = 0; $b = 1;
    for ($i = 2; $i <= $n; $i++) {
        $temp = $a + $b;
        $a = $b;
        $b = $temp;
    }
    return $b;
}

$start = microtime(true);
$fibPHP = fibonacciPHP(50);
$tempoPHP = microtime(true) - $start;

echo "  Via PHP Puro: $fibPHP\n";
echo "  Tempo: " . number_format($tempoPHP * 1000000, 2) . " μs\n\n";

// Teste 3: Soma de Array via FFI
echo "Soma de Array (1 milhão de elementos):\n";

$elementos = 1_000_000;
$arr = FFI::new("int64_t[$elementos]");

// Preenche o array
for ($i = 0; $i < $elementos; $i++) {
    $arr[$i] = $i + 1;
}

$start = microtime(true);
$somaRust = $ffi->soma_array($arr, $elementos);
$tempoSomaRust = microtime(true) - $start;

echo "  Via Rust FFI: $somaRust\n";
echo "  Tempo: " . number_format($tempoSomaRust * 1000, 4) . " ms\n\n";

// Comparativo com array_sum PHP
$arrPHP = range(1, $elementos);
$start = microtime(true);
$somaPHP = array_sum($arrPHP);
$tempoSomaPHP = microtime(true) - $start;

echo "  Via PHP (array_sum): $somaPHP\n";
echo "  Tempo: " . number_format($tempoSomaPHP * 1000, 4) . " ms\n";