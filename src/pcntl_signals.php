<?php
// src/pcntl_signals.php
// Demonstracao: Worker com shutdown graceful via pcntl_signal
//
// IMPORTANTE: pcntl so funciona em Linux/Unix.
// Execute dentro do container Docker.
//
// Uso:
//   php src/pcntl_signals.php
//
// Para testar SIGTERM:
//   kill -SIGTERM <pid>

declare(strict_types=1);

if (!extension_loaded('pcntl')) {
    echo "ERRO: Extensao 'pcntl' nao disponivel.\n";
    echo "Execute dentro do container Docker:\n";
    echo "  docker exec -it php_performance php src/pcntl_signals.php\n";
    exit(1);
}

$shmopDisponivel = extension_loaded('shmop');

echo "=================================================\n";
echo "  Worker com Shutdown Graceful (pcntl_signal)\n";
echo "=================================================\n\n";

echo "PID: " . getmypid() . "\n";
echo "Para testar: kill -SIGTERM " . getmypid() . "\n";
echo "Ou pressione Ctrl+C\n\n";

// Estado global
$running = true;
$jobAtual = null;
$jobsProcessados = 0;
$shmId = null;
$shmKey = 0xFF99;

// Handlers de sinais
function handleSigterm(int $signo): void {
    global $running, $jobAtual, $jobsProcessados;
    echo "\n[SIGTERM] Recebido - finalizando graciosamente...\n";
    echo "[SIGTERM] Jobs processados: $jobsProcessados\n\n";
    $running = false;
}

function handleSigint(int $signo): void {
    global $running;
    echo "\n[SIGINT] Ctrl+C detectado - encerrando...\n\n";
    $running = false;
}

function handleSighup(int $signo): void {
    echo "\n[SIGHUP] Reload solicitado (continuando)\n";
}

function handleSigalrm(int $signo): void {
    echo "[HEARTBEAT] " . (time() - $GLOBALS['startTime']) . "s rodando\n";
    pcntl_alarm(5);
}

// Registra handlers
echo "[SETUP] Registrando handlers...\n";
pcntl_signal(SIGTERM, 'handleSigterm');
pcntl_signal(SIGINT, 'handleSigint');
pcntl_signal(SIGHUP, 'handleSighup');
pcntl_signal(SIGALRM, 'handleSigalrm');

$GLOBALS['startTime'] = time();
pcntl_alarm(5);

echo "[SETUP] SIGTERM, SIGINT, SIGHUP, SIGALRM registrados\n\n";

// Cria memoria compartilhada (para demo de cleanup)
if ($shmopDisponivel) {
    echo "[SHMOP] Criando bloco de memoria compartilhada...\n";
    $shmId = @shmop_open($shmKey, "c", 0644, 1024);
    if ($shmId) {
        shmop_write($shmId, serialize(['pid' => getmypid()]), 0);
        echo "[SHMOP] OK - sera limpo no shutdown\n\n";
    } else {
        $shmId = null;
    }
}

// Loop principal
echo "[WORKER] Iniciando (jobs simulados ~0.5s cada)...\n\n";

$jobId = 1;

while ($running) {
    pcntl_signal_dispatch();
    
    if (!$running) break;
    
    $jobAtual = "JOB-" . str_pad((string)$jobId, 4, '0', STR_PAD_LEFT);
    echo "[WORKER] Processando $jobAtual... ";
    
    usleep(500000);
    
    pcntl_signal_dispatch();
    
    if (!$running) {
        echo "INTERROMPIDO!\n";
        break;
    }
    
    echo "OK\n";
    $jobsProcessados++;
    $jobAtual = null;
    $jobId++;
    
    if ($jobId > 10) {
        echo "\n[WORKER] Limite de 10 jobs (demo)\n";
        $running = false;
    }
}

// Cleanup
echo "\n=== Cleanup ===\n";

if ($shmopDisponivel && $shmId) {
    echo "[CLEANUP] Deletando memoria compartilhada...\n";
    shmop_delete($shmId);
    echo "[CLEANUP] OK\n";
}

echo "\n=== Resumo ===\n";
echo "Jobs processados: $jobsProcessados\n";
echo "Tempo: " . (time() - $GLOBALS['startTime']) . "s\n";
echo "Encerramento: GRACEFUL\n";

echo "\n=================================================\n";
echo "  FIM\n";
echo "=================================================\n";
