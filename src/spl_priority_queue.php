<?php
// src/spl_priority_queue.php
// Benchmark: SplPriorityQueue vs Array + usort para filas com prioridade
//
// Em sistemas reais (job queues, processadores de eventos), itens tem prioridades diferentes.
// SplPriorityQueue usa um binary heap interno: insercao e extracao sao O(log n).
// Ordenar array com usort() a cada operacao e O(n log n) por operacao.
//
// Uso:
//   php src/spl_priority_queue.php

declare(strict_types=1);

ini_set('memory_limit', '512M');

echo "=================================================\n";
echo "  Benchmark: SplPriorityQueue vs Array + usort\n";
echo "  Simulacao: Fila de Jobs com Diferentes Prioridades\n";
echo "=================================================\n\n";

// ================================
// SECAO 1: DEMONSTRACAO DIDATICA DO HEAP BINARIO
// ================================
echo "-------------------------------------\n";
echo " PARTE 1: Como funciona um Binary Heap (Max-Heap)\n";
echo "-------------------------------------\n\n";

echo "Um binary heap e uma arvore binaria onde cada PAI tem prioridade >= FILHOS.\n";
echo "O item de MAIOR prioridade fica sempre na RAIZ (topo).\n\n";

echo "Vamos simular a insercao de 7 jobs com diferentes prioridades:\n\n";

// Cria uma fila de demonstracao
$demo = new SplPriorityQueue();
$demo->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

$jobsDemo = [
    ['nome' => 'EmailA',     'prioridade' => 10],
    ['nome' => 'AlertaB',    'prioridade' => 100],  // Alta prioridade
    ['nome' => 'RelatC',     'prioridade' => 1],
    ['nome' => 'TransacD',   'prioridade' => 50],
    ['nome' => 'AlertaE',    'prioridade' => 100],  // Alta prioridade
    ['nome' => 'EmailF',     'prioridade' => 10],
    ['nome' => 'TransacG',   'prioridade' => 50],
];

echo "Inserindo jobs na fila (observe como os de maior prioridade sobem):\n";
echo str_repeat("-", 75) . "\n";

foreach ($jobsDemo as $index => $job) {
    $demo->insert($job['nome'], $job['prioridade']);
    printf("  [%d] Inserido: %-12s (prioridade %3d) -> Heap size: %d\n", 
           $index + 1, 
           $job['nome'], 
           $job['prioridade'], 
           $demo->count()
    );
}

echo "\nOrdem de EXTRACAO (sempre remove o de MAIOR prioridade primeiro):\n";
echo str_repeat("-", 75) . "\n";

$ordem = 1;
while (!$demo->isEmpty()) {
    $item = $demo->extract();
    printf("  [%d] Extraido: %-12s (prioridade %3d) -> Restam: %d\n", 
           $ordem++, 
           $item['data'], 
           $item['priority'], 
           $demo->count()
    );
}

echo "\nNote: Jobs com prioridade 100 saem primeiro, depois 50, depois 10, depois 1.\n";
echo "Dentro da mesma prioridade, a ordem pode variar (heap nao garante estabilidade).\n";

// ================================
// SECAO 2: COMPARACAO DE PERFORMANCE
// ================================
echo "\n\n-------------------------------------\n";
echo " PARTE 2: Benchmark de Performance (50.000 jobs)\n";
echo "-------------------------------------\n\n";

// ================================
// CENARIO: Job queue com diferentes prioridades
// - Prioridade 100: Alertas de seguranca
// - Prioridade 50:  Transacoes financeiras
// - Prioridade 10:  Emails transacionais
// - Prioridade 1:   Relatorios batch
// ================================

$totalJobs = 50000;
$jobsParaProcessar = 25000;

// Niveis de prioridade (peso -> descricao)
$prioridades = [
    100 => 'ALERTA_SEGURANCA',
    50  => 'TRANSACAO_FINANCEIRA', 
    10  => 'EMAIL_TRANSACIONAL',
    1   => 'RELATORIO_BATCH'
];

$pesosPossiveis = array_keys($prioridades);

echo "Cenario Realista: Sistema de Processamento de Jobs\n\n";
echo "  Total de jobs inseridos: " . number_format($totalJobs, 0, ',', '.') . "\n";
echo "  Jobs a processar (maior prioridade): " . number_format($jobsParaProcessar, 0, ',', '.') . "\n";
echo "  Niveis de prioridade: " . implode(', ', $pesosPossiveis) . "\n";
echo "  Tipos de job:\n";
foreach ($prioridades as $peso => $tipo) {
    printf("    [%3d] %s\n", $peso, $tipo);
}
echo "\n";

// ================================
// TESTE 1: Array + usort (INEFICIENTE)
// ================================
echo "[TESTE 1] Abordagem Tradicional (Array + usort)\n\n";
echo "  Caracteristicas:\n";
echo "    - Ordenacao inicial com usort(): O(n log n)\n";
echo "    - Cada array_shift(): O(n) por reindexacao\n";
echo "    - Complexidade total: O(n^2) - MUITO INEFICIENTE\n\n";

gc_collect_cycles();
$memStart = memory_get_usage();
$timeStart = microtime(true);

$arrayQueue = [];

// Insere todos os jobs com prioridade aleatoria
for ($i = 0; $i < $totalJobs; $i++) {
    $prioridade = $pesosPossiveis[array_rand($pesosPossiveis)];
    $arrayQueue[] = [
        'id' => $i,
        'prioridade' => $prioridade,
        'tipo' => $prioridades[$prioridade],
        'dados' => "Payload do job #$i"
    ];
}

// Ordena inicialmente (maior prioridade primeiro)
usort($arrayQueue, fn($a, $b) => $b['prioridade'] <=> $a['prioridade']);

// Extrai os jobs de maior prioridade
$processados = [];
for ($i = 0; $i < $jobsParaProcessar; $i++) {
    // array_shift remove do inicio (O(n) pela reindexacao)
    $job = array_shift($arrayQueue);
    $processados[] = $job['id'];
    
    // NOTA: Em cenario real, novos jobs chegam e precisamos reordenar.
    // Para simplificar, nao inserimos novos jobs aqui, mas o custo 
    // de array_shift ja demonstra o problema.
}

$timeArray = microtime(true) - $timeStart;
$memArray = memory_get_usage() - $memStart;

unset($arrayQueue, $processados);
gc_collect_cycles();

echo "  Resultados:\n";
echo "    Tempo: " . number_format($timeArray, 4) . "s\n";
echo "    Memoria: " . number_format($memArray / 1024 / 1024, 2) . " MB\n";

// ================================
// TESTE 2: SplPriorityQueue (OTIMIZADO)
// ================================
echo "\n[TESTE 2] Estrutura de Dados Especializada (SplPriorityQueue)\n\n";
echo "  Caracteristicas:\n";
echo "    - Binary heap (max-heap) nativo em C\n";
echo "    - Cada insert(): O(log n) - bubble up automatico\n";
echo "    - Cada extract(): O(log n) - bubble down automatico\n";
echo "    - Complexidade total: O(n log n) - OTIMIZADO\n\n";

gc_collect_cycles();
$memStart = memory_get_usage();
$timeStart = microtime(true);

$splQueue = new SplPriorityQueue();

// Configura para extrair dados + prioridade (util para debug)
// Opcoes: EXTR_DATA (so dados), EXTR_PRIORITY (so prioridade), EXTR_BOTH (ambos)
$splQueue->setExtractFlags(SplPriorityQueue::EXTR_DATA);

// Insere todos os jobs (O(log n) por insercao - heap automatico)
for ($i = 0; $i < $totalJobs; $i++) {
    $prioridade = $pesosPossiveis[array_rand($pesosPossiveis)];
    $splQueue->insert([
        'id' => $i,
        'tipo' => $prioridades[$prioridade],
        'dados' => "Payload do job #$i"
    ], $prioridade); // Segundo parametro e a prioridade
}

// Extrai os jobs de maior prioridade (O(log n) por extracao)
$processados = [];
for ($i = 0; $i < $jobsParaProcessar; $i++) {
    $job = $splQueue->extract();
    $processados[] = $job['id'];
}

$timeSpl = microtime(true) - $timeStart;
$memSpl = memory_get_usage() - $memStart;

echo "  Resultados:\n";
echo "    Tempo: " . number_format($timeSpl, 4) . "s\n";
echo "    Memoria: " . number_format($memSpl / 1024 / 1024, 2) . " MB\n";

// ================================
// COMPARACAO FINAL
// ================================
echo "\n\n-------------------------------------\n";
echo " PARTE 3: Analise Comparativa\n";
echo "-------------------------------------\n\n";

$speedup = $timeArray / $timeSpl;
$memSavings = (1 - ($memSpl / max($memArray, 1))) * 100;

echo "GANHOS DE PERFORMANCE:\n\n";
printf("  Velocidade:  %.2fx mais rapido (SplPriorityQueue)\n", $speedup);
if ($memSavings > 0) {
    printf("  Economia:    %.2f%% menos memoria\n", $memSavings);
}
echo "\n";

echo "COMPLEXIDADE ALGORITMICA:\n\n";
echo "  Array + usort:\n";
echo "    1. Ordenacao inicial: O(n log n)\n";
echo "    2. Cada array_shift(): O(n) - precisa reindexar todo o array\n";
echo "    3. Total para $jobsParaProcessar extracoes: O(n^2) - QUADRATICO!\n";
echo "    NOTA: Com 100k jobs, seria ~10 bilhoes de operacoes\n\n";

echo "  SplPriorityQueue (Binary Heap):\n";
echo "    1. Cada insercao: O(log n) - heap 'bubble up' automatico\n";
echo "    2. Cada extracao: O(log n) - heap 'bubble down' automatico\n";
echo "    3. Total: O(n log n) - LOGARITMICO!\n";
echo "    NOTA: Com 100k jobs, seria ~1.6 milhao de operacoes\n";
echo "    Ganho teorico: ~6.250x mais rapido em escala\n";

echo "\n\n-------------------------------------\n";
echo " PARTE 4: Armadilhas e Solucoes Praticas\n";
echo "-------------------------------------\n\n";

echo "[ARMADILHA 1] setExtractFlags()\n\n";
echo "  Problema:\n";
echo "    Por padrao, extract() retorna APENAS os dados, nao a prioridade.\n";
echo "    Se voce precisa saber qual era a prioridade do item extraido,\n";
echo "    vai receber apenas os dados e perder a informacao de prioridade.\n\n";
echo "  Solucao:\n";
echo "    \$queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);\n";
echo "    // Retorna: ['data' => ..., 'priority' => ...]\n\n";
echo "  Opcoes disponiveis:\n";
echo "    - EXTR_DATA (padrao)  - Retorna so os dados\n";
echo "    - EXTR_PRIORITY        - Retorna so a prioridade\n";
echo "    - EXTR_BOTH            - Retorna array com ambos\n\n";

echo "[ARMADILHA 2] Estabilidade (Ordem FIFO)\n\n";
echo "  Problema:\n";
echo "    Itens com a MESMA prioridade nao tem ordem garantida (FIFO).\n";
echo "    Exemplo: 3 emails com prioridade 10 podem sair em qualquer ordem.\n";
echo "    Isso pode causar comportamento imprevisivel em sistemas que\n";
echo "    esperam que jobs mais antigos sejam processados primeiro.\n\n";
echo "  Solucao: Prioridade Composta\n";
echo "    Use um numero de sequencia crescente para desempate:\n";
echo "    \$prioridadeComposta = (\$prioridade * 1000000) + (1000000 - \$sequencia);\n";
echo "    Exemplo:\n";
echo "      Job A (p=50, seq=0): 50000000 + (1000000-0) = 51000000\n";
echo "      Job B (p=50, seq=1): 50000000 + (1000000-1) = 50999999\n";
echo "      Job C (p=50, seq=2): 50000000 + (1000000-2) = 50999998\n";
echo "      -> Job A sai primeiro (FIFO garantido!)\n\n";

echo "[ARMADILHA 3] Iteracao Destrutiva\n\n";
echo "  Problema:\n";
echo "    SplPriorityQueue nao e iteravel como um array comum.\n";
echo "    Ao usar foreach() ou iterator, a fila e CONSUMIDA (extraida).\n";
echo "    Apos a iteracao, a fila fica vazia!\n\n";
echo "  Solucao:\n";
echo "    Se precisar iterar sem consumir, clone a fila antes:\n";
echo "    \$clone = clone \$queue;\n";
echo "    foreach (\$clone as \$item) { ... }\n";
echo "    // \$queue ainda tem todos os itens\n";

// ================================
// SECAO 3: DEMONSTRACAO VISUAL DA ARMADILHA 2
// ================================
echo "\n\n-------------------------------------\n";
echo " PARTE 5: Demonstracao da Solucao para Estabilidade (FIFO)\n";
echo "-------------------------------------\n\n";

$filaEstavel = new SplPriorityQueue();
$filaEstavel->setExtractFlags(SplPriorityQueue::EXTR_DATA);

echo "Cenario: 5 emails todos com prioridade 'MEDIA' (50)\n";
echo "Objetivo: Garantir que sejam processados na ordem FIFO (First-In-First-Out)\n\n";

// Insere 5 jobs todos com prioridade "alta" (50), mas em ordem especifica
$sequencia = 0;
foreach (['Email A', 'Email B', 'Email C', 'Email D', 'Email E'] as $email) {
    // Prioridade composta: (prioridade_base * 100000) + (100000 - sequencia)
    // Subtraimos sequencia para que itens MAIS ANTIGOS tenham prioridade MAIOR
    $prioridadeComposta = (50 * 100000) + (100000 - $sequencia);
    $filaEstavel->insert($email, $prioridadeComposta);
    printf("  [OK] Inserido: %-10s (prioridade composta: %d)\n", $email, $prioridadeComposta);
    $sequencia++;
}

echo "\nOrdem de EXTRACAO (respeitando FIFO):\n";

$ordem = [];
while (!$filaEstavel->isEmpty()) {
    $item = $filaEstavel->extract();
    $ordem[] = $item;
}
echo "  " . implode(' -> ', $ordem) . "\n\n";

echo "Resultado: A ordem de insercao foi preservada (FIFO)!\n";
echo "Dica: Em producao, use um contador atomico (Redis INCR) para a sequencia.\n\n";

echo "=================================================\n";
echo "     FIM DO BENCHMARK - SplPriorityQueue\n";
echo "=================================================\n";
