<?php
// src/bitwise_flags.php
// Demonstração REAL de Bitwise Flags com Benchmark de CSV

// 1. Configuração e Constantes
const STATUS_PENDENTE     = 0b000; 
const STATUS_CONCLUIDO    = 0b001; 
const STATUS_CANCELADO    = 0b010; 
const STATUS_ENVIADO      = 0b011; 
const STATUS_ENTREGUE     = 0b100;

// Método Pagamento (Bits 3 e 4)
const PAGTO_CARTAO_CREDITO = 0b00 << 3;
const PAGTO_PIX            = 0b01 << 3; 
const PAGTO_BOLETO         = 0b10 << 3; 
const PAGTO_CARTAO_DEBITO  = 0b11 << 3;

$mapaStatus = [
    'Pendente'  => STATUS_PENDENTE,
    'Concluido' => STATUS_CONCLUIDO,
    'Cancelado' => STATUS_CANCELADO,
    'Enviado'   => STATUS_ENVIADO,
    'Entregue'  => STATUS_ENTREGUE,
];

$mapaPagto = [
    'Cartao Credito' => PAGTO_CARTAO_CREDITO,
    'Pix'            => PAGTO_PIX,
    'Boleto'         => PAGTO_BOLETO,
    'Cartao Debito'  => PAGTO_CARTAO_DEBITO,
];

// CLI Argument Handling
if ($argc < 2) {
    die("Uso: php src/bitwise_flags.php <caminho_do_csv>\nExemplo: php src/bitwise_flags.php vendas.csv\n");
}

$csvPath = $argv[1];
if (!file_exists($csvPath)) {
    die("Erro: Arquivo '$csvPath' não encontrado.\n");
}

echo "=== Benchmark: Strings vs Bitwise Flags ===\n";
echo "Arquivo: $csvPath\n";

// --- Fase 1: Abordagem Tradicional (Strings) ---
echo "\n[1] Carregando dataset como Strings (Arrays Associativos)...\n";
gc_collect_cycles(); // Limpeza prévia
$startMem = memory_get_usage();
$startTime = microtime(true);

$datasetTradicional = [];
$handle = fopen($csvPath, 'r');
$header = fgetcsv($handle); // Pula header
// Descobre índices das colunas
$idxStatus = array_search('status_pedido', $header);
$idxPagto = array_search('metodo_pagamento', $header);

if ($idxStatus === false || $idxPagto === false) {
    die("Erro: Colunas 'status_pedido' ou 'metodo_pagamento' não encontradas no CSV.\n");
}

while (($row = fgetcsv($handle)) !== false) {
    // Na abordagem tradicional, guardamos as strings originais
    $datasetTradicional[] = [
        'status' => $row[$idxStatus],
        'pagto'  => $row[$idxPagto]
    ];
}
fclose($handle);

$timeTradicional = microtime(true) - $startTime;
$memTradicional = memory_get_usage() - $startMem;
$count = count($datasetTradicional);

echo "   Linhas carregadas: " . number_format($count) . "\n";
echo "   Tempo: " . number_format($timeTradicional, 4) . "s\n";
echo "   Memória: " . number_format($memTradicional / 1024 / 1024, 2) . " MB\n";

// Limpa memória para o próximo teste
unset($datasetTradicional);
gc_collect_cycles();

// --- Fase 2: Abordagem Otimizada (Bitwise + SplFixedArray) ---
echo "\n[2] Carregando dataset Otimizado (Bitwise Ints em SplFixedArray)...\n";
$startMem = memory_get_usage();
$startTime = microtime(true);

// Pré-aloca array fixo (se soubermos o tamanho, melhor. Se não, array normal de ints já é bom, mas vamos testar array normal de ints para ser justo com PHP moderno ou SplFixedArray se conseguirmos contar linhas antes? Vamos de Array simples de Ints para simplificar o código, já que PHP 7+ arrays Packed são muito eficientes para ints)
// Correção: Para "Performance Extrema", SplFixedArray economiza a estrutura de Hash Bucket. Vamos ler o arquivo 2x? Não, vamos usar array normal primeiro, depois convertemos, ou melhor: Array dinamico de ints.
// Array de inteiros em PHP 8 é muito eficiente (Packed Array).
$datasetOtimizado = []; 
$handle = fopen($csvPath, 'r');
fgetcsv($handle); // Header

while (($row = fgetcsv($handle)) !== false) {
    $stStr = $row[$idxStatus];
    $pgStr = $row[$idxPagto];

    // Mapeamento (Lookup O(1))
    // Operador de Coalescência null (?? 0) para segurança
    $stBit = $mapaStatus[$stStr] ?? 0;
    $pgBit = $mapaPagto[$pgStr] ?? 0;

    // Compactação
    $datasetOtimizado[] = ($stBit | $pgBit);
}
fclose($handle);

// Opcional: Converter para SplFixedArray para compactar ainda mais (remove overhead de crescimento dinâmico)
$datasetOtimizado = SplFixedArray::fromArray($datasetOtimizado);

$timeOtimizado = microtime(true) - $startTime;
$memOtimizado = memory_get_usage() - $startMem;

echo "   Linhas carregadas: " . number_format(count($datasetOtimizado)) . "\n";
echo "   Tempo: " . number_format($timeOtimizado, 4) . "s\n";
echo "   Memória: " . number_format($memOtimizado / 1024 / 1024, 2) . " MB\n";

// --- Resultados ---
echo "\n=== Resultados ===\n";
$economiaMem = 100 - (($memOtimizado / $memTradicional) * 100);
echo "Economia de Memória: " . number_format($economiaMem, 2) . "%\n";
echo "Fator de Redução: " . number_format($memTradicional / $memOtimizado, 1) . "x menor\n";

// Validação (pegar um item aleatório para provar que funciona)
$randIdx = rand(0, $count - 1);
$val = $datasetOtimizado[$randIdx];
// Decodificar
$stVal = $val & 0b111;
$pgVal = ($val & 0b11000); // Mantem shifted para busca no array
$invStatus = array_flip($mapaStatus);
$invPagto = array_flip($mapaPagto);

echo "\n[Validação Randomica - Índice $randIdx]\n";
echo "Valor Int: $val\n";
echo "Status Decodificado: " . ($invStatus[$stVal] ?? '?') . "\n";
echo "Pagto Decodificado: " . ($invPagto[$pgVal] ?? '?') . "\n";
