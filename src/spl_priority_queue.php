<?php
// src/spl_priority_queue.php
// Benchmark: SplPriorityQueue vs Array + usort
//
// Uso:
//   php src/spl_priority_queue.php

declare(strict_types=1);

ini_set('memory_limit', '512M');

echo "=================================================\n";
echo "  Benchmark: SplPriorityQueue vs Array + usort\n";
echo "=================================================\n\n";

// ================================
// DEMONSTRACAO: Como funciona o Binary Heap
// ================================
echo "-------------------------------------\n";
echo " Demonstracao: Binary Heap em acao\n";
echo "-------------------------------------\n\n";

$demo = new SplPriorityQueue();
$demo->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

$jobsDemo = [
    ['nome' => 'EmailA',     'prioridade' => 10],
    ['nome' => 'AlertaB',    'prioridade' => 100],
    ['nome' => 'RelatC',     'prioridade' => 1],
    ['nome' => 'TransacD',   'prioridade' => 50],
    ['nome' => 'AlertaE',    'prioridade' => 100],
    ['nome' => 'EmailF',     'prioridade' => 10],
    ['nome' => 'TransacG',   'prioridade' => 50],
];

echo "Inserindo jobs:\n";
foreach ($jobsDemo as $index => $job) {
    $demo->insert($job['nome'], $job['prioridade']);
    printf("  [%d] %-12s (prioridade %3d) -> size: %d\n", 
           $index + 1, $job['nome'], $job['prioridade'], $demo->count());
}

echo "\nExtraindo (maior prioridade primeiro):\n";
$ordem = 1;
while (!$demo->isEmpty()) {
    $item = $demo->extract();
    printf("  [%d] %-12s (prioridade %3d)\n", 
           $ordem++, $item['data'], $item['priority']);
}

// ================================
// BENCHMARK: 50.000 jobs
// ================================
echo "\n\n-------------------------------------\n";
echo " Benchmark: 50.000 jobs\n";
echo "-------------------------------------\n\n";

$totalJobs = 50000;
$jobsParaProcessar = 25000;
$prioridades = [100, 50, 10, 1];

// TESTE 1: Array + usort
echo "[TESTE 1] Array + usort\n\n";

gc_collect_cycles();
$memStart = memory_get_usage();
$timeStart = microtime(true);

$arrayQueue = [];

for ($i = 0; $i < $totalJobs; $i++) {
    $prioridade = $prioridades[array_rand($prioridades)];
    $arrayQueue[] = [
        'id' => $i,
        'prioridade' => $prioridade,
        'dados' => "Payload #$i"
    ];
}

usort($arrayQueue, fn($a, $b) => $b['prioridade'] <=> $a['prioridade']);

$processados = [];
for ($i = 0; $i < $jobsParaProcessar; $i++) {
    $job = array_shift($arrayQueue);
    $processados[] = $job['id'];
}

$timeArray = microtime(true) - $timeStart;
$memArray = memory_get_usage() - $memStart;

unset($arrayQueue, $processados);
gc_collect_cycles();

echo "  Tempo: " . number_format($timeArray, 4) . "s\n";
echo "  Memoria: " . number_format($memArray / 1024 / 1024, 2) . " MB\n";

// TESTE 2: SplPriorityQueue
echo "\n[TESTE 2] SplPriorityQueue\n\n";

gc_collect_cycles();
$memStart = memory_get_usage();
$timeStart = microtime(true);

$splQueue = new SplPriorityQueue();
$splQueue->setExtractFlags(SplPriorityQueue::EXTR_DATA);

for ($i = 0; $i < $totalJobs; $i++) {
    $prioridade = $prioridades[array_rand($prioridades)];
    $splQueue->insert([
        'id' => $i,
        'dados' => "Payload #$i"
    ], $prioridade);
}

$processados = [];
for ($i = 0; $i < $jobsParaProcessar; $i++) {
    $job = $splQueue->extract();
    $processados[] = $job['id'];
}

$timeSpl = microtime(true) - $timeStart;
$memSpl = memory_get_usage() - $memStart;

echo "  Tempo: " . number_format($timeSpl, 4) . "s\n";
echo "  Memoria: " . number_format($memSpl / 1024 / 1024, 2) . " MB\n";

// ================================
// RESULTADOS
// ================================
echo "\n\n-------------------------------------\n";
echo " Resultados\n";
echo "-------------------------------------\n\n";

$speedup = $timeArray / $timeSpl;
$memSavings = (1 - ($memSpl / max($memArray, 1))) * 100;

printf("Speedup: %.2fx mais rapido\n", $speedup);
if ($memSavings > 0) {
    printf("Economia: %.2f%% menos memoria\n", $memSavings);
}

// ================================
// DEMONSTRACAO: Estabilidade FIFO
// ================================
echo "\n\n-------------------------------------\n";
echo " Demonstracao: Prioridade Composta (FIFO)\n";
echo "-------------------------------------\n\n";

$filaEstavel = new SplPriorityQueue();
$filaEstavel->setExtractFlags(SplPriorityQueue::EXTR_DATA);

$sequencia = 0;
foreach (['Email A', 'Email B', 'Email C', 'Email D', 'Email E'] as $email) {
    $prioridadeComposta = (50 * 100000) + (100000 - $sequencia);
    $filaEstavel->insert($email, $prioridadeComposta);
    printf("  Inserido: %-10s (prioridade: %d)\n", $email, $prioridadeComposta);
    $sequencia++;
}

echo "\nExtracao (FIFO preservado):\n  ";
$ordem = [];
while (!$filaEstavel->isEmpty()) {
    $ordem[] = $filaEstavel->extract();
}
echo implode(' -> ', $ordem) . "\n";

echo "\n=================================================\n";
echo "  FIM DO BENCHMARK\n";
echo "=================================================\n";
