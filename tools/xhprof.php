#!/usr/bin/php
<?php

class XHProfRunner {
    
    public function run($args) {
        $scriptPath = array_shift($args);

        if (empty($scriptPath)) {
             echo "Uso: php xhprof.php <script.php> [argumentos...]\n";
             exit(1);
        }

        if (!file_exists($scriptPath)) {
            echo "Aviso: O arquivo '$scriptPath' não foi encontrado localmente.\n";
        }

        // ==================================
        // PREPARAÇÃO DO MONITORAMENTO
        // ==================================
        // Arquivos temporários para o "espião" e para os dados coletados
        $id = uniqid();
        $outputFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 
            'xhprof_data_' . $id . '.json';
        $monitorFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 
            'xhprof_mon_' . $id . '.php';
        
        // O código que será injetado
        // Aqui ligamos o XHProf com flags de CPU e Memória
        $monitorCode = '<?php 
        // Se a extensão não estiver carregada, falha silenciosamente ou avisa
        if (function_exists("xhprof_enable")) {
            xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
        }

        register_shutdown_function(function(){ 
            if (function_exists("xhprof_disable")) {
                $data = xhprof_disable();
                $f = getenv("XHPROF_OUTPUT_FILE");
                
                // Salvamos em JSON para facilitar a leitura no PHP pai
                if($f) {
                    file_put_contents($f, json_encode($data));
                }
            }
        });';
        
        file_put_contents($monitorFile, $monitorCode);
        
        // Avisa ao processo filho onde salvar
        putenv("XHPROF_OUTPUT_FILE=$outputFile");

        // ==================================
        // MONTAGEM DO COMANDO
        // ==================================
        $escapedArgs = array_map('escapeshellarg', $args);
        $cmdArgs = implode(' ', $escapedArgs);
        
        $phpBinary = PHP_BINARY;
        $target = escapeshellarg($scriptPath);
        $monitorPath = escapeshellarg($monitorFile);
        
        // Injeta nosso monitor antes de rodar o script
        $command = "\"$phpBinary\" -d auto_prepend_file=$monitorPath " . 
                   "$target $cmdArgs";

        echo "\n[XHProf] Iniciando Profiling...\n";
        echo "Script: $scriptPath\n";
        echo str_repeat("=", 60) . "\n";

        // ==================================
        // EXECUÇÃO
        // ==================================
        $startTime = microtime(true);
        $exitCode = 0;
        
        passthru($command, $exitCode);

        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        // ==================================
        // ANÁLISE DOS DADOS
        // ==================================
        $rawData = [];
        if (file_exists($outputFile)) {
            $json = file_get_contents($outputFile);
            $rawData = json_decode($json, true) ?? [];
            @unlink($outputFile);
        }
        @unlink($monitorFile);

        // Se não gerou dados, provavelmente a extensão não está instalada
        if (empty($rawData)) {
            echo "\n\n[Erro] Nenhum dado coletado.\n";
            echo "Verifique se a extensão 'xhprof' está instalada.\n";
            exit(1);
        }

        // ==================================
        // RELATÓRIO
        // ==================================
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "[XHProf] Top 10 Gargalos (Tempo Inclusivo)\n";
        echo str_repeat("-", 60) . "\n";
        
        $this->renderReport($rawData);
        
        echo str_repeat("=", 60) . "\n";
        echo "Tempo Total de Execução: " . number_format($duration, 4) . "s\n\n";

        return 0;
    }

    private function renderReport($data) {
        // O XHProf retorna chaves no formato "Parent==>Child"
        // Vamos agregar tudo pelo nome da função "Child" para simplificar
        $aggregated = [];

        foreach ($data as $key => $metrics) {
            // Se tiver '==>', pega só o nome final da função
            $parts = explode('==>', $key);
            $funcName = end($parts);

            // Ignora a própria função principal main() se quiser
            if ($funcName === 'main()') continue;

            if (!isset($aggregated[$funcName])) {
                $aggregated[$funcName] = [
                    'ct'  => 0, // Contagem de chamadas
                    'wt'  => 0, // Wall Time (microsegundos)
                    'cpu' => 0, // CPU Time
                    'mu'  => 0  // Memory Usage (bytes)
                ];
            }

            $aggregated[$funcName]['ct']  += $metrics['ct'];
            $aggregated[$funcName]['wt']  += $metrics['wt'];
            $aggregated[$funcName]['cpu'] += $metrics['cpu'];
            $aggregated[$funcName]['mu']  += $metrics['mu'];
        }

        // Ordena por Wall Time (Decrescente)
        uasort($aggregated, function($a, $b) {
            return $b['wt'] <=> $a['wt'];
        });

        // Cabeçalho
        // Ajustamos o espaçamento para caber em 80 colunas
        // Nome(30) | Qtd(6) | Tempo(10) | Mem(9)
        echo sprintf(
            "%-30s | %6s | %10s | %9s\n", 
            "Função", "Qtd", "Tempo(ms)", "Mem(KB)"
        );
        echo str_repeat("-", 60) . "\n";

        $count = 0;
        foreach ($aggregated as $func => $info) {
            if ($count++ >= 10) break;

            // Formatação
            // WT vem em microsegundos -> divide por 1000 para ms
            $timeMs = number_format($info['wt'] / 1000, 2);
            
            // Memória em bytes -> divide por 1024 para KB
            $memKb = number_format($info['mu'] / 1024, 2);

            // Nome cortado se for muito longo
            $displayName = (strlen($func) > 28) 
                ? substr($func, 0, 25) . '...' 
                : $func;

            echo sprintf(
                "%-30s | %6d | %10s | %9s\n",
                $displayName, 
                $info['ct'], 
                $timeMs, 
                $memKb
            );
        }
    }
}

// Remove o nome do script e roda
array_shift($argv);
$runner = new XHProfRunner();
exit($runner->run($argv));
