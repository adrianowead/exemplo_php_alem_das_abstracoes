<?php
// src/spl_queue_benchmark.php
// Benchmark: SplQueue vs Array operations para filas FIFO

ini_set('memory_limit', '512M');

echo "=== Benchmark: SplQueue vs Array (FIFO) ===\n\n";

$iterations = 100000;

// ========================================
// TESTE 1: Array com array_shift/array_push
// ========================================
echo "[1] Array tradicional com array_shift/array_push\n";
gc_collect_cycles();
$memStart = memory_get_usage();
$timeStart = microtime(true);

$arrayQueue = [];

// Enfileira
for ($i = 0; $i < $iterations; $i++) {
    $arrayQueue[] = ['id' => $i, 'data' => str_repeat('x', 100)];
}

// Desenfileira metade
for ($i = 0; $i < $iterations / 2; $i++) {
    array_shift($arrayQueue); // O(n) - reindexação!
}

$timeArray = microtime(true) - $timeStart;
$memArray = memory_get_usage() - $memStart;

unset($arrayQueue);
gc_collect_cycles();

echo "   Tempo: " . number_format($timeArray, 4) . "s\n";
echo "   Memória: " . number_format($memArray / 1024 / 1024, 2) . " MB\n";

// ========================================
// TESTE 2: SplQueue (Otimizado)
// ========================================
echo "\n[2] SplQueue (Estrutura FIFO Nativa)\n";
gc_collect_cycles();
$memStart = memory_get_usage();
$timeStart = microtime(true);

$splQueue = new SplQueue();

// Enfileira
for ($i = 0; $i < $iterations; $i++) {
    $splQueue->enqueue(['id' => $i, 'data' => str_repeat('x', 100)]);
}

// Desenfileira metade
for ($i = 0; $i < $iterations / 2; $i++) {
    $splQueue->dequeue(); // O(1) - sem reindexação!
}

$timeSpl = microtime(true) - $timeStart;
$memSpl = memory_get_usage() - $memStart;

echo "   Tempo: " . number_format($timeSpl, 4) . "s\n";
echo "   Memória: " . number_format($memSpl / 1024 / 1024, 2) . " MB\n";

// ========================================
// COMPARAÇÃO
// ========================================
echo "\n=== Resultados ===\n";
$speedup = $timeArray / $timeSpl;
$memSavings = (1 - ($memSpl / $memArray)) * 100;

echo "Speedup: " . number_format($speedup, 2) . "x mais rápido\n";
echo "Economia de Memória: " . number_format($memSavings, 2) . "%\n";

echo "\nMotivo: array_shift() precisa reindexar todos os elementos (O(n)).\n";
echo "SplQueue::dequeue() apenas move um ponteiro interno (O(1)).\n";
