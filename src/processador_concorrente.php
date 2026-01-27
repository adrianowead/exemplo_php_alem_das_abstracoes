<?php
// src/processador_concorrente.php
// Demonstração de Concorrência Cooperativa com Fibers (PHP 8.1+)
// Simula um processamento de ETL enriquecido com chamadas de rede (mockadas).

if (PHP_VERSION_ID < 80100) {
    die("Erro: Este script requer PHP 8.1+ para Fibers.\n");
}

class Scheduler {
    protected $fibers = [];

    public function add(Fiber $fiber): void {
        $this->fibers[] = $fiber;
    }

    public function run(): void {
        while (!empty($this->fibers)) {
            foreach ($this->fibers as $i => $fiber) {
                try {
                    if (!$fiber->isStarted()) {
                        $fiber->start();
                    } elseif ($fiber->isSuspended()) {
                        $fiber->resume();
                    } elseif ($fiber->isTerminated()) {
                        unset($this->fibers[$i]);
                    }
                } catch (Throwable $e) {
                    echo "Erro na Fiber: " . $e->getMessage() . "\n";
                    unset($this->fibers[$i]);
                }
            }
            // Evita CPU spin em loop muito apertado se todas estiverem aguardando
            // Num sistema real, aqui entraria o event loop (stream_select)
            usleep(100); 
        }
    }

    public function isEmpty(): bool {
        return empty($this->fibers);
    }
}

// Simula uma chamada de API assíncrona (ex: Consulta de Frete ou Validação de CPF)
// Na prática: suspendemos a execução para dar chance a outro pedido ser processado.
function consultaExternaSimulada(int $id, int $delayMs) {
    // Suspendemos a fiber, "fingindo" que estamos esperando I/O de rede
    $steps = $delayMs / 10; // Quebra em passos de 10ms
    for ($i=0; $i<$steps; $i++) {
        Fiber::suspend(); 
        // Nota: Sem um event loop real, o resume() é manual no Scheduler.
        // Aqui estamos cooperando: "Volto daqui a pouco, processador".
    }
    return "OK (Pedido $id)";
}

// ===================================
// Execução
// ===================================

$csvFile = $argv[1] ?? 'vendas.csv';
if (!file_exists($csvFile)) die("Arquivo não encontrado.");

$scheduler = new Scheduler();
$fp = fopen($csvFile, 'r');
fgetcsv($fp); // Header

echo "=== Processamento Concorrente de Pedidos ===\n";
echo "Lendo CSV e despachando Fibers...\n";

$maxConcurrency = 500; // Máximo de fibers simultâneas
$active = 0;
$processed = 0;
$start = microtime(true);

while (($row = fgetcsv($fp)) !== false) {
    $pedidoId = (int)$row[0];
    
    // Cria uma Fiber para processar este pedido isoladamente
    $fiber = new Fiber(function() use ($pedidoId) {
        // Lógica de Negócio isolada
        // 1. Validação local (CPU bound - rápido)
        // ...
        
        // 2. Consulta Externa (I/O bound - lento)
        // O delay é aleatório para criar "caos" de tempos de resposta
        $res = consultaExternaSimulada($pedidoId, mt_rand(10, 50));
        
        // Opcional: imprimir progresso (comentado para evitar flood)
        // echo "."; 
    });

    $scheduler->add($fiber);
    $active++;
    $processed++;

    // Gerenciamento de Backpressure
    // Se encheu a fila de concorrência, roda o scheduler até limpar um pouco
    if ($active >= $maxConcurrency) {
        $scheduler->run(); // Isso vai rodar até ESVAZIAR tudo neste scheduler simples. 
                           // Uma otimização seria rodar até liberar X slots. 
                           // Para simplificar o exemplo, processamos em "batches" de 500.
        $active = 0;
        echo "\rProcessados: $processed...";
    }
}

// Roda os restantes
$scheduler->run();
fclose($fp);

$duration = microtime(true) - $start;
echo "\n\nTotal Processado: $processed\n";
echo "Tempo Total: " . number_format($duration, 2) . "s\n";
echo "Média: " . number_format($processed / $duration, 2) . " req/s\n";
