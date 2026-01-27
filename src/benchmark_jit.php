<?php
/**
 * src/benchmark_jit.php
 * Benchmark de CPU Puro (CPU Bound) - Fractal de Mandelbrot
 *
 * Objetivo: Demonstrar o poder do JIT (Just-In-Time Compiler) do PHP 8+.
 * O PHP tradicional é interpretado (Opcode -> VM).
 * Com JIT, opcodes viram Assembly nativo da CPU.
 *
 * Em tarefas de I/O (banco, rede), o JIT ajuda pouco.
 * Em tarefas matemáticas (fractais, criptografia, ML), o JIT brilha.
 *
 * Uso:
 * 1. Sem JIT: php -d opcache.jit_buffer_size=0 src/benchmark_jit.php
 * 2. Com JIT: php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=100M -d opcache.jit=1255 src/benchmark_jit.php
 */

// Verifica status do JIT
$opcacheStatus = opcache_get_status();
$jitEnabled = isset($opcacheStatus['jit']['enabled']) && $opcacheStatus['jit']['enabled'];
$jitBuffer = isset($opcacheStatus['jit']['buffer_size']) ? $opcacheStatus['jit']['buffer_size'] : 0;

echo "=== Benchmark JIT: Mandelbrot Set ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "JIT Status: " . ($jitEnabled ? "ENABLED (Buffer: $jitBuffer bytes)" : "DISABLED") . "\n";
echo ($jitEnabled ? "🚀 Modo TURBO ativado!" : "🐢 Modo Interpretado (padrão)") . "\n";
echo "Calculando fractal (agaurde)...\n\n";

$start = microtime(true);

// Configuração do Fractal
$w = 80;  // Largura do terminal
$h = 40;  // Altura do terminal
$y_start = -1.0;
$y_end = 1.0;
$x_start = -2.0;
$x_end = 1.0;
$max_iter = 10000; // Aumentar iterações para forçar a CPU

$dx = ($x_end - $x_start) / $w;
$dy = ($y_end - $y_start) / $h;

$output = "";

for ($y = 0; $y < $h; $y++) {
    $ci = $y_start + $y * $dy;
    
    for ($x = 0; $x < $w; $x++) {
        $cr = $x_start + $x * $dx;
        $zr = 0.0;
        $zi = 0.0;
        $iter = 0;
        
        // Laço matemático pesado (Candidato perfeito para JIT)
        while ($zr * $zr + $zi * $zi < 4.0 && $iter < $max_iter) {
            $check = $zr * $zr - $zi * $zi + $cr;
            $zi = 2.0 * $zr * $zi + $ci;
            $zr = $check;
            $iter++;
        }
        
        // Renderização ASCII
        if ($iter == $max_iter) {
            $output .= " "; // Dentro do conjunto (Preto)
        } else {
            // Gradiente de caracteres baseado na velocidade de escape
            $chars = ".,-~:;=!*#$@"; 
            $output .= $chars[$iter % strlen($chars)];
        }
    }
    $output .= "\n";
}

$end = microtime(true);
$duration = $end - $start;

echo $output;
echo "\n====================================\n";
echo "Tempo de Execução: " . number_format($duration, 4) . " segundos.\n";
echo "Iterações Máximas por Pixel: $max_iter\n";
echo "Total de Pixels: " . ($w * $h) . "\n";
echo "====================================\n";

if ($duration > 1.0 && $jitEnabled) {
    echo "NOTA: Se ainda pareceu lento, verifique se o 'opcache.jit' está configurado corretamente (ex: 1255).\n";
} elseif ($duration < 0.2 && !$jitEnabled) {
    echo "NOTA: Rápido demais? Aumente \$max_iter para ver a diferença.\n";
}
