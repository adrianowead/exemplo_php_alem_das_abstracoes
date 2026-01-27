<?php
/**
 * src/ffi_libc.php
 * Demonstração básica de FFI com a libc
 * 
 * Mostra como chamar funções C padrão diretamente do PHP
 * sem precisar de extensões customizadas.
 */

// Verifica se FFI está disponível
if (!extension_loaded('ffi')) {
    die("Erro: Extensão FFI não está habilitada.\n" .
        "Adicione 'extension=ffi' ao php.ini e 'ffi.enable=true'.\n");
}

echo "=== FFI Demo: Funções da libc ===\n\n";

// Funções da libc
$libc = FFI::cdef("
    typedef long time_t;
    
    size_t strlen(const char *s);
    int abs(int j);
    int rand(void);
    void srand(unsigned int seed);
    time_t time(time_t *tloc);
", "libc.so.6");

// Funções matemáticas da libm
$libm = FFI::cdef("
    double sqrt(double x);
    double pow(double x, double y);
", "libm.so.6");

// Teste strlen
$texto = "Técnicas PHP de Performance Extrema";
echo "strlen('$texto'):\n";
echo "  C (FFI): " . $libc->strlen($texto) . " bytes\n";
echo "  PHP:    " . strlen($texto) . " bytes\n\n";

// Teste abs
echo "abs(-42):\n";
echo "  C (FFI): " . $libc->abs(-42) . "\n";
echo "  PHP:    " . abs(-42) . "\n\n";

// Teste sqrt
echo "sqrt(144):\n";
echo "  C (FFI): " . $libm->sqrt(144) . "\n";
echo "  PHP:    " . sqrt(144) . "\n\n";

// Teste pow
echo "pow(2, 10):\n";
echo "  C (FFI): " . $libm->pow(2, 10) . "\n";
echo "  PHP:    " . pow(2, 10) . "\n\n";

// Teste rand (com seed)
$libc->srand($libc->time(null));
echo "Números aleatórios via rand():\n";
for ($i = 0; $i < 5; $i++) {
    echo "  " . $libc->rand() . "\n";
}

echo "\nConclusão: FFI permite acesso direto a qualquer biblioteca C do sistema.\n";