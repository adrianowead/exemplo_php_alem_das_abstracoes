<?php
// src/weakmap_benchmark.php
// Benchmark: WeakMap vs Array para caches associados a objetos
//
// Uso:
//   php src/weakmap_benchmark.php

declare(strict_types=1);

ini_set('memory_limit', '512M');

echo "=================================================\n";
echo "  Benchmark: WeakMap vs Array Cache\n";
echo "=================================================\n\n";

// Classe simples para demonstracao
class Usuario {
    public function __construct(public readonly string $nome) {}
}

// ================================
// DEMONSTRACAO: Referencia Forte vs Fraca
// ================================
echo "-------------------------------------\n";
echo " Demonstracao: Referencia Forte vs Fraca\n";
echo "-------------------------------------\n\n";

// TESTE COM ARRAY (referencia forte)
echo "[1] Array tradicional:\n";

$cacheArray = [];
$usuario1 = new Usuario('Alice');
$cacheArray[spl_object_id($usuario1)] = ['role' => 'admin'];

echo "  - Objeto criado, adicionado ao cache\n";
echo "  - Tamanho do cache: " . count($cacheArray) . "\n";

unset($usuario1);
gc_collect_cycles();

echo "  - Apos unset() e GC: " . count($cacheArray) . " entrada(s)\n";
echo "  - Dados orfaos permanecem no cache!\n\n";

unset($cacheArray);

// TESTE COM WEAKMAP (referencia fraca)
echo "[2] WeakMap:\n";

$cacheWeak = new WeakMap();
$usuario2 = new Usuario('Bob');
$cacheWeak[$usuario2] = ['role' => 'user'];

echo "  - Objeto criado, adicionado ao WeakMap\n";
echo "  - Tamanho do WeakMap: " . count($cacheWeak) . "\n";

unset($usuario2);
gc_collect_cycles();

echo "  - Apos unset() e GC: " . count($cacheWeak) . " entrada(s)\n";
echo "  - Entrada removida automaticamente!\n";

unset($cacheWeak);

// ================================
// BENCHMARK: Simulacao de Memory Leak
// ================================
echo "\n\n-------------------------------------\n";
echo " Benchmark: 50.000 objetos em batches\n";
echo "-------------------------------------\n\n";

class Entidade {
    public function __construct(
        public readonly int $id,
        public readonly string $tipo
    ) {}
}

$iterations = 50000;

// TESTE 1: Array (memory leak)
echo "[TESTE 1] Array tradicional\n\n";

gc_collect_cycles();
$memInicial = memory_get_usage(true);
$memPico = 0;

$cacheArray = [];
$objetosAtivos = [];

for ($i = 0; $i < $iterations; $i++) {
    $obj = new Entidade($i, 'produto');
    
    $cacheArray[spl_object_id($obj)] = [
        'hash' => md5(serialize($obj)),
        'processado_em' => microtime(true),
        'flags' => random_int(0, 255)
    ];
    
    if ($i % 1000 === 999) {
        unset($objetosAtivos);
        $objetosAtivos = [];
        gc_collect_cycles();
    } else {
        $objetosAtivos[] = $obj;
    }
    
    $memAtual = memory_get_usage(true);
    if ($memAtual > $memPico) {
        $memPico = $memAtual;
    }
}

$memFinalArray = memory_get_usage(true);
$tamanhoCache = count($cacheArray);

echo "  Entradas no cache: " . number_format($tamanhoCache) . "\n";
echo "  Memoria inicial: " . number_format($memInicial / 1024 / 1024, 2) . " MB\n";
echo "  Memoria final:   " . number_format($memFinalArray / 1024 / 1024, 2) . " MB\n";
echo "  Pico de memoria: " . number_format($memPico / 1024 / 1024, 2) . " MB\n";
echo "  Vazamento:       " . number_format(($memFinalArray - $memInicial) / 1024 / 1024, 2) . " MB\n";

unset($cacheArray, $objetosAtivos);
gc_collect_cycles();
sleep(1);

// TESTE 2: WeakMap (sem leak)
echo "\n[TESTE 2] WeakMap\n\n";

gc_collect_cycles();
$memInicial = memory_get_usage(true);
$memPico = 0;

$cacheWeak = new WeakMap();
$objetosAtivos = [];

for ($i = 0; $i < $iterations; $i++) {
    $obj = new Entidade($i, 'produto');
    
    $cacheWeak[$obj] = [
        'hash' => md5(serialize($obj)),
        'processado_em' => microtime(true),
        'flags' => random_int(0, 255)
    ];
    
    if ($i % 1000 === 999) {
        unset($objetosAtivos);
        $objetosAtivos = [];
        gc_collect_cycles();
    } else {
        $objetosAtivos[] = $obj;
    }
    
    $memAtual = memory_get_usage(true);
    if ($memAtual > $memPico) {
        $memPico = $memAtual;
    }
}

$memFinalWeak = memory_get_usage(true);
$tamanhoCache = count($cacheWeak);

echo "  Entradas no cache: " . number_format($tamanhoCache) . "\n";
echo "  Memoria inicial: " . number_format($memInicial / 1024 / 1024, 2) . " MB\n";
echo "  Memoria final:   " . number_format($memFinalWeak / 1024 / 1024, 2) . " MB\n";
echo "  Pico de memoria: " . number_format($memPico / 1024 / 1024, 2) . " MB\n";
echo "  Vazamento:       " . number_format(($memFinalWeak - $memInicial) / 1024 / 1024, 2) . " MB\n";

// ================================
// COMPARACAO
// ================================
echo "\n\n-------------------------------------\n";
echo " Resultados\n";
echo "-------------------------------------\n\n";

$leakArray = $memFinalArray - $memInicial;
$leakWeak = $memFinalWeak - $memInicial;
$economia = $leakArray - $leakWeak;

echo "Array:   " . number_format($leakArray / 1024 / 1024, 2) . " MB vazamento\n";
echo "WeakMap: " . number_format($leakWeak / 1024 / 1024, 2) . " MB vazamento\n";
echo "Economia: " . number_format($economia / 1024 / 1024, 2) . " MB\n";

if ($leakArray > 0) {
    $percentual = (($leakArray - $leakWeak) / $leakArray) * 100;
    echo "Reducao: " . number_format($percentual, 1) . "%\n";
}

echo "\n=================================================\n";
echo "  FIM DO BENCHMARK\n";
echo "=================================================\n";
