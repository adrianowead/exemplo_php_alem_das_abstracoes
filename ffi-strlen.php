<?php
/**
 * Exemplo básico de FFI: chamando strlen() da libc
 */

// Define a assinatura da função C
$ffi = FFI::cdef("
    size_t strlen(const char *s);
", "libc.so.6");

$texto = "Performance é tudo em PHP!";

// Chama a função C nativa
$tamanho = $ffi->strlen($texto);

echo "Texto: '$texto'\n";
echo "Tamanho via FFI (C): $tamanho caracteres\n";
echo "Tamanho via PHP: " . strlen($texto) . " caracteres\n";