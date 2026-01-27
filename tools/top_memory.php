<?php
// tools/top_memory.php
// Monitor de memória "sidecar" para processos PHP.
// Uso: php tools/top_memory.php <PID> [limite_mb]

// **Desabilita buffering de saída**
if (ob_get_level()) ob_end_clean();
ob_implicit_flush(true);

$pid = $argv[1] ?? null;
$limitMb = $argv[2] ?? 128;
$demoMode = false;

if (!$pid) {
    echo ">> Nenhum PID informado.\n";
    echo ">> Iniciando modo DEMONSTRAÇÃO (Self-Test)...\n";
    $demoMode = true;
    
    // Script "vítima" que consome memória propositalmente
    $code = '<?php
ini_set("memory_limit", "512M");
$arr = [];

// Envia PID e sinal de início
echo "CHILD_PID:" . getmypid() . "\n";
echo "CHILD_STARTED\n";
flush();

// Consome memória gradualmente
for ($i=0; $i<60; $i++) {
    $arr[] = str_repeat("X", 1024 * 1024); // +1MB
    usleep(500000); // 500ms
}
sleep(2);
';
    
    $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memory_leak_demo_' . getmypid() . '.php';
    file_put_contents($tmpFile, $code);
    
    // Comando direto sem shell
    $cmd = PHP_BINARY . ' ' . escapeshellarg($tmpFile);
    
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    
    $proc = proc_open($cmd, $descriptors, $pipes);
    
    if (!is_resource($proc)) {
        die("Falha ao criar processo filho.\n");
    }
    
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    
    // Aguarda sinal com timeout maior
    echo ">> Aguardando processo filho iniciar...\n";
    $startTime = time();
    $started = false;
    $childPid = null;
    
    while (time() - $startTime < 10) {
        $line = fgets($pipes[1]);
        if ($line !== false) {
            $line = trim($line);
            // Captura o PID enviado pelo filho
            if (preg_match('/^CHILD_PID:(\d+)$/', $line, $matches)) {
                $childPid = (int)$matches[1];
            }
            if ($line === 'CHILD_STARTED') {
                $started = true;
                break;
            }
        }
        usleep(100000);
    }
    
    if (!$started) {
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        die("Timeout esperando processo filho. STDERR: $stderr\n");
    }
    
    // Usa o PID que o filho nos enviou
    if ($childPid) {
        $pid = $childPid;
    } else {
        // Fallback: tenta pegar do proc_get_status (funciona no Linux)
        $status = proc_get_status($proc);
        $pid = $status['pid'];
    }
    
    $limitMb = 50;
    
    echo ">> Processo filho iniciado (PID: $pid)\n";
    echo ">> Simulando vazamento de memória...\n\n";
}

echo "=== Top Memory Watchdog ===\n";
echo "Monitorando PID: $pid | Limite: {$limitMb}MB\n\n";

$os = PHP_OS_FAMILY;
$peak = 0;
$alertShown = false;

while (true) {
    $memBytes = 0;
    $running = true;

    if ($os === 'Windows') {
        $cmd = "powershell -NoProfile -Command \"Get-Process -Id $pid -ErrorAction SilentlyContinue | Select-Object -ExpandProperty PrivateMemorySize64\"";
        $output = [];
        exec($cmd, $output, $ret);
        
        if ($ret === 0 && !empty($output) && ctype_digit(trim($output[0]))) {
            $memBytes = (int)trim($output[0]);
        } else {
            $running = false;
        }
    } else {
        // Linux
        $statusFile = "/proc/$pid/status";
        if (file_exists($statusFile)) {
            $status = @file_get_contents($statusFile);
            if ($status && preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches)) {
                $memBytes = (int)$matches[1] * 1024;
            } else {
                $running = false;
            }
        } else {
            $running = false;
        }
    }

    if (!$running) {
        echo "\n\nProcesso encerrado.\n";
        break;
    }

    $memMb = $memBytes / 1024 / 1024;
    $peak = max($peak, $memMb);

    $bars = str_repeat('#', (int)($memMb / 5));
    
    // Limpa a linha inteira antes de escrever (80 espaços)
    echo "\r" . str_repeat(' ', 80) . "\r";
    printf("PID %d: %7.2f MB [%-20s] Pico: %7.2f MB", 
        $pid, $memMb, substr($bars, 0, 20), $peak);

    if ($memMb > $limitMb && !$alertShown) {
        echo "\n\n";
        echo str_repeat('=', 60) . "\n";
        echo "  [ALERTA] LIMITE DE MEMÓRIA EXCEDIDO!\n";
        echo "  Limite: {$limitMb} MB\n";
        echo "  Atual:  " . number_format($memMb, 2) . " MB\n";
        echo str_repeat('=', 60) . "\n\n";
        $alertShown = true;
    }

    usleep(500000); // 500ms
}

// Cleanup
if ($demoMode && isset($proc) && is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
    if (isset($tmpFile) && file_exists($tmpFile)) {
        @unlink($tmpFile);
    }
}

echo "\nPico de memória: " . number_format($peak, 2) . " MB\n";