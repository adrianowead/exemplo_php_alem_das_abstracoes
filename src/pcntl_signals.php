<?php
// src/pcntl_signals.php
// Demonstracao: Worker com shutdown graceful via pcntl_signal
//
// Em ambientes containerizados (Kubernetes, Docker Swarm), processos recebem SIGTERM
// antes de serem finalizados. Um script PHP comum morre abruptamente, podendo corromper
// dados ou deixar recursos pendentes. Este script demonstra como capturar sinais e
// fazer cleanup adequado.
//
// IMPORTANTE: pcntl so funciona em Linux/Unix. Nao funciona no Windows nativo.
// Execute dentro do container Docker.
//
// Uso:
//   php src/pcntl_signals.php
//
// Para testar shutdown graceful, em outro terminal:
//   kill -SIGTERM <pid>    (PID sera exibido no inicio do script)
//   ou Ctrl+C no proprio terminal (envia SIGINT)

declare(strict_types=1);

// Verificacao de ambiente
if (!extension_loaded('pcntl')) {
    echo "ERRO: Extensao 'pcntl' nao disponivel.\n";
    echo "Esta extensao so funciona em Linux/Unix.\n";
    echo "Execute este script dentro do container Docker:\n";
    echo "  docker compose run --rm php php src/pcntl_signals.php\n";
    exit(1);
}

if (!extension_loaded('shmop')) {
    echo "AVISO: Extensao 'shmop' nao disponivel. Exemplos de memoria compartilhada desabilitados.\n";
    $shmopDisponivel = false;
} else {
    $shmopDisponivel = true;
}

echo "=== Worker com Shutdown Graceful (pcntl_signal) ===\n\n";
echo "PID deste processo: " . getmypid() . "\n";
echo "Para testar, envie SIGTERM de outro terminal:\n";
echo "  kill -SIGTERM " . getmypid() . "\n";
echo "Ou pressione Ctrl+C para enviar SIGINT.\n\n";

// ========================================
// ESTADO GLOBAL DO WORKER
// ========================================
$running = true;       // Flag para loop principal
$jobAtual = null;      // Job sendo processado (para log)
$jobsProcessados = 0;  // Contador
$shmId = null;         // Handle de memoria compartilhada (se usado)
$shmKey = 0xFF99;      // Chave para bloco de memoria

// ========================================
// HANDLERS DE SINAIS
// ========================================

/**
 * Handler para SIGTERM (kubernetes stop, docker stop)
 * E o sinal "educado" - pede para o processo encerrar graciosamente.
 */
function handleSigterm(int $signo): void {
    global $running, $jobAtual, $jobsProcessados;
    
    echo "\n[SINAL] Recebido SIGTERM ($signo) - Solicitacao de encerramento\n";
    echo "[SINAL] Job atual: " . ($jobAtual ?? 'nenhum') . "\n";
    echo "[SINAL] Jobs processados ate agora: $jobsProcessados\n";
    echo "[SINAL] Finalizando apos concluir job atual...\n\n";
    
    $running = false;
}

/**
 * Handler para SIGINT (Ctrl+C no terminal)
 * Similar ao SIGTERM, mas geralmente vem de interacao humana.
 */
function handleSigint(int $signo): void {
    global $running, $jobAtual, $jobsProcessados;
    
    echo "\n[SINAL] Recebido SIGINT ($signo) - Ctrl+C detectado\n";
    echo "[SINAL] Encerrando graciosamente...\n\n";
    
    $running = false;
}

/**
 * Handler para SIGHUP (terminal fechado, ou "reload config" em daemons)
 * Neste exemplo, apenas logamos. Em producao, poderia recarregar configuracoes.
 */
function handleSighup(int $signo): void {
    echo "\n[SINAL] Recebido SIGHUP ($signo) - Terminal desconectado ou reload solicitado\n";
    echo "[SINAL] Em producao, recarregariamos configuracoes aqui.\n";
    echo "[SINAL] Continuando execucao...\n\n";
    // Nao setamos $running = false - apenas logamos
}

/**
 * Handler para SIGALRM (timer interno)
 * Usado para timeouts ou tarefas periodicas.
 */
function handleSigalrm(int $signo): void {
    echo "[HEARTBEAT] Ping! Script rodando a " . (time() - $GLOBALS['startTime']) . " segundos.\n";
    // Reagenda o alarme para daqui 5 segundos
    pcntl_alarm(5);
}

// ========================================
// REGISTRA OS HANDLERS
// ========================================
echo "[SETUP] Registrando handlers de sinais...\n";

pcntl_signal(SIGTERM, 'handleSigterm');
pcntl_signal(SIGINT, 'handleSigint');
pcntl_signal(SIGHUP, 'handleSighup');
pcntl_signal(SIGALRM, 'handleSigalrm');

$GLOBALS['startTime'] = time();

// Ativa heartbeat a cada 5 segundos via SIGALRM
pcntl_alarm(5);

echo "[SETUP] Handlers registrados: SIGTERM, SIGINT, SIGHUP, SIGALRM\n\n";

// ========================================
// SIMULA CRIACAO DE MEMORIA COMPARTILHADA
// (para demonstrar cleanup no shutdown)
// ========================================
if ($shmopDisponivel) {
    echo "[SHMOP] Criando bloco de memoria compartilhada (para demo de cleanup)...\n";
    $shmId = @shmop_open($shmKey, "c", 0644, 1024);
    if ($shmId) {
        $estado = serialize(['pid' => getmypid(), 'started' => date('Y-m-d H:i:s')]);
        shmop_write($shmId, $estado, 0);
        echo "[SHMOP] Bloco criado. Sera limpo no shutdown graceful.\n\n";
    } else {
        echo "[SHMOP] Falha ao criar bloco (ignorando).\n\n";
        $shmId = null;
    }
}

// ========================================
// LOOP PRINCIPAL DO WORKER
// ========================================
echo "[WORKER] Iniciando processamento de jobs...\n";
echo "[WORKER] (Jobs sao simulados - cada um leva ~0.5 segundo)\n\n";

$jobId = 1;

while ($running) {
    // IMPORTANTE: Despacha sinais pendentes
    // Sem isso, os handlers nunca sao chamados!
    pcntl_signal_dispatch();
    
    // Se foi sinalizado para parar, sai do loop
    if (!$running) {
        break;
    }
    
    // Simula processamento de um job
    $jobAtual = "JOB-" . str_pad((string)$jobId, 4, '0', STR_PAD_LEFT);
    echo "[WORKER] Processando $jobAtual... ";
    
    // Simula trabalho (0.5 segundos)
    usleep(500000);
    
    // Verifica sinais novamente (para resposta mais rapida)
    pcntl_signal_dispatch();
    
    if (!$running) {
        echo "INTERROMPIDO!\n";
        echo "[WORKER] Job $jobAtual foi interrompido durante processamento.\n";
        echo "[WORKER] Em producao, devolveriamos o job a fila.\n";
        break;
    }
    
    echo "OK\n";
    $jobsProcessados++;
    $jobAtual = null;
    $jobId++;
    
    // Limita o demo a 13 jobs para nao rodar infinito
    if ($jobId > 13) {
        echo "\n[WORKER] Limite de 13 jobs atingido (demo). Encerrando.\n";
        $running = false;
    }
}

// ========================================
// CLEANUP (SHUTDOWN GRACEFUL)
// ========================================
echo "\n=== Iniciando Cleanup ===\n";

// 1. Libera memoria compartilhada
if ($shmopDisponivel && $shmId) {
    echo "[CLEANUP] Deletando bloco de memoria compartilhada...\n";
    shmop_delete($shmId);
    shmop_close($shmId);
    echo "[CLEANUP] Memoria compartilhada liberada.\n";
}

// 2. Fecha conexoes (simulado)
echo "[CLEANUP] Fechando conexoes (simulado)...\n";

// 3. Salva checkpoint (simulado)
echo "[CLEANUP] Salvando estado/checkpoint (simulado)...\n";

// 4. Log final
echo "\n=== Resumo do Worker ===\n";
echo "Jobs processados com sucesso: $jobsProcessados\n";
echo "Tempo de execucao: " . (time() - $GLOBALS['startTime']) . " segundos\n";
echo "Encerramento: GRACEFUL (recursos liberados corretamente)\n";

echo "\n=== Fim ===\n";
echo "O worker encerrou de forma controlada, sem corromper dados.\n";
echo "Em producao, isso evita jobs duplicados, dados corrompidos e recursos orfaos.\n";
