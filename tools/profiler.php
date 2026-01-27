#!/usr/bin/php
<?php

class ScriptProfiler {
    
    public function profile($args) {
        // O primeiro argumento após o profiler.php é o script alvo
        $scriptPath = array_shift($args);

        if (empty($scriptPath)) {
             echo "Uso: php profiler.php <caminho-do-script.php> [argumentos...]\n";
             exit(1);
        }

        if (!file_exists($scriptPath)) {
            echo "Aviso: O arquivo '$scriptPath' não foi encontrado localmente.\n";
        }

        // ==================================
        // PREPARAÇÃO DO MONITORAMENTO
        // ==================================
        // Cria um arquivo temporário para injetar o monitoramento de memória
        // e outro para receber os dados
        $statsFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prof_stats_' . uniqid() . '.txt';
        $monitorFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prof_mon_' . uniqid() . '.php';
        
        // Código injetado que roda antes do script (mas registra o shutdown para rodar depois)
        $monitorCode = '<?php 
        register_shutdown_function(function(){ 
            $f = getenv("PROFILER_STATS_FILE"); 
            if($f) {
                $mem = memory_get_peak_usage(true); // true = real memory
                file_put_contents($f, $mem);
            }
        });';
        
        file_put_contents($monitorFile, $monitorCode);
        
        // Passa o arquivo de stats via variável de ambiente para o processo filho
        putenv("PROFILER_STATS_FILE=$statsFile");

        // ==================================
        // MONTAGEM DO COMANDO
        // ==================================
        $escapedArgs = array_map('escapeshellarg', $args);
        $cmdArgs = implode(' ', $escapedArgs);
        
        $phpBinary = PHP_BINARY;
        $target = escapeshellarg($scriptPath);
        $monitorPath = escapeshellarg($monitorFile);
        
        // -d auto_prepend_file carrega nosso monitor automaticamente
        $command = "\"$phpBinary\" -d auto_prepend_file=$monitorPath $target $cmdArgs";

        echo "\n[Profiler] Iniciando execução...\n";
        echo "Script: $scriptPath\n";
        echo "Comando: $command\n";
        echo str_repeat("=", 60) . "\n";

        // ==================================
        // EXECUÇÃO
        // ==================================
        $startTime = microtime(true);
        $exitCode = 0;
        
        // Executa!
        passthru($command, $exitCode);

        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        // ==================================
        // COLETA E LIMPEZA
        // ==================================
        $peakMemory = 0;
        if (file_exists($statsFile)) {
            $peakMemory = (int)file_get_contents($statsFile);
            @unlink($statsFile);
        }
        @unlink($monitorFile);

        // ==================================
        // RELATÓRIO
        // ==================================
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "[Profiler] Relatório de Execução\n";
        echo str_repeat("-", 60) . "\n";
        
        $status = $exitCode === 0 ? 'Ok' : 'Erro';
        
        echo "Status: $status (Código de Saída: $exitCode)\n";
        echo "Tempo Total: " . number_format($duration, 4) . " segundos\n";
        echo "Pico de Memória (Real): " . $this->formatBytes($peakMemory) . "\n";
        
        echo str_repeat("=", 60) . "\n\n";

        return 0; // Sempre sucesso para o profiler
    }

    private function formatBytes($bytes) {
        if ($bytes <= 0) return "0 B (Indisponível)";
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return number_format($bytes / 1024, 2) . ' KB';
        return number_format($bytes / 1048576, 2) . ' MB';
    }
}

// Remove o próprio nome do script (profiler.php) dos argumento
array_shift($argv);

$profiler = new ScriptProfiler();
$exitCode = $profiler->profile($argv);
exit($exitCode);