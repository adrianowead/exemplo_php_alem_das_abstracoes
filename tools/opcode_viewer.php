<?php
// tools/opcode_viewer.php
// Analisa o status do OPcache para um script específico.
// Para ver os OPcodes reais (Assembly do PHP), é necessário a extensão VLD ou PHP compilado com debug.
// Este script foca nas métricas que o Opcache expõe nativamente.

$script = $argv[1] ?? null;

if (!$script || !file_exists($script)) {
    die("Uso: php tools/opcode_viewer.php <arquivo.php>\n");
}

// Força configurações essenciais para o teste funcionar no CLI sem mexer no php.ini
// Nota: opcache.enable_cli só pode ser alterado se for PHP_INI_SYSTEM ou PHP_INI_PERDIR?
// Não, opcache.enable_cli é PHP_INI_SYSTEM, então não dá pra mudar com ini_set() em runtime se já começou desligado.
// Mas podemos tentar ajustar outros parâmetros que afetam a visibilidade.
ini_set('opcache.file_update_protection', '0'); // Importante: permite cachear arquivos criados agora
ini_set('opcache.validate_timestamps', '1');
ini_set('opcache.revalidate_freq', '0');

if (!function_exists('opcache_compile_file') || !ini_get('opcache.enable_cli')) {
    die("Erro: Opcache desligado no CLI.\nRode com: php -d opcache.enable_cli=1 tools/opcode_viewer.php <arquivo>\n");
}

echo "=== Análise de Compilação (Opcache) ===\n";
echo "Script Alvo: $script\n";

// Opcache usa caminho real/absoluto como chave nas versões recentes
$realPath = realpath($script);

// 1. Compilação Manual
$start = microtime(true);
// Usamos realPath na compilação para garantir alinhamento com o cache
$result = opcache_compile_file($realPath);
$duration = (microtime(true) - $start) * 1000; // ms

if ($result) {
    echo "Compilação: SUCESSO\n";
    echo "Tempo de Compilação: " . number_format($duration, 4) . " ms\n";
} else {
    echo "Compilação: FALHA\n";
    exit(1);
}

// 2. Introspecção do Cache
// Verifica explicitamente se está cacheado
if (function_exists('opcache_is_script_cached') && !opcache_is_script_cached($realPath)) {
    echo "Aviso Crítico: opcache_is_script_cached() retornou FALSE. O arquivo não foi persistido no cache.\n";
    echo "Verifique permissões ou se 'opcache.memory_consumption' está cheio.\n";
}

$status = opcache_get_status(true);
$scripts = $status['scripts'] ?? [];

// Em Windows, as barras podem variar
$keyFound = null;

// Busca manual pela chave (para evitar problemas de barra / ou \)
foreach ($scripts as $path => $info) {
    if (norm($path) === norm($realPath)) {
        $keyFound = $path;
        break;
    }
}

if ($keyFound) {
    $info = $scripts[$keyFound];
    echo "\n[Detalhes Internos]\n";
    echo "Hits: " . $info['hits'] . "\n";
    echo "Memória Usada: " . number_format($info['memory_consumption']) . " bytes\n";
    echo "Last Used: " . date('H:i:s', $info['last_used_timestamp']) . "\n";
    echo "Timestamp Criação: " . date('H:i:s', $info['timestamp']) . "\n";
} else {
    echo "Aviso: Script compilado mas não encontrado no relatório de status.\n";
    
    // Fallback: tenta opcache_invalidate para forçar uma nova tentativa em runs futuros?
    // Não, apenas diagnóstico.
    
    if (count($scripts) < 5) {
        echo "Scripts no cache: " . implode(', ', array_keys($scripts)) . "\n";
    } else {
        echo "Total scripts no cache: " . count($scripts) . "\n";
    }
}

echo "\n--- Dica para ver Assembly Real ---\n";
echo "Para ver os OPcodes linha-a-linha (como 'ECHO', 'ADD', 'DO_FCALL'), instale a extensão VLD e rode:\n";
echo "php -d vld.active=1 -d vld.execute=0 $script\n";

function norm($p) {
    // Normaliza tudo para barras do sistema atual e lowercase
    return strtolower(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $p));
}
