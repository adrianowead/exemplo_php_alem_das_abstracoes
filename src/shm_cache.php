<?php
// src/shm_cache.php
// Cache de Produtos usando Memória Compartilhada (SHMOP).
// Permite que múltiplos processos PHP (ex: workers do ETL) acessem a tabela de preços
// sem alocar arrays duplicados na RAM de cada processo.
//
// Uso: 
//   php src/shm_cache.php write  (Cria e popula o cache)
//   php src/shm_cache.php read   (Lê do cache)
//   php src/shm_cache.php clear  (Apaga)

if (!extension_loaded('shmop')) {
    die("Erro: Extensão 'shmop' não carregada.\n");
}

$modo = $argv[1] ?? 'read';
// Chave única para o bloco de memória (system V IPC key)
// Em Windows, usamos um inteiro fixo se ftok falhar ou para simplicidade
$shmKey = 0xFF3; 

// Tamanho fixo do bloco: vamos supor 1MB para nosso catálogo
$shmSize = 1024 * 1024; 

if ($modo === 'write') {
    echo "=== SHM: Writer ===\n";
    // Cria ou abre (c = create, 0644 = rw-r--r--)
    $shmId = shmop_open($shmKey, "c", 0644, $shmSize);
    
    if (!$shmId) die("Falha ao criar bloco de memória compartilhada.\n");

    // Simula dados úteis para compartilhar entre Processos/Workers
    // Ex: Configurações Globais, Flags de Features ou Cache de Produtos Populares
    echo "Gerando dados de cache (Top Produtos + Config)...\n";
    
    $p = [
        'meta' => [
            'updated_at' => date('Y-m-d H:i:s'),
            'source'     => 'Redis/DB Slave',
            'pid'        => getmypid()
        ],
        'config' => [
            'maintenance_mode' => false,
            'discount_active'  => true,
            'tax_rate'         => 12.5
        ],
        'top_products' => [
            10 => ['name' => 'Notebook Gamer X1', 'price' => 4500.00, 'stock' => 15],
            22 => ['name' => 'Mouse Óptico Pro',  'price' => 120.50,  'stock' => 200],
            35 => ['name' => 'Teclado Mecânico',  'price' => 350.00,  'stock' => 42],
            500 => ['name' => 'Monitor 27" 4K',   'price' => 1899.90, 'stock' => 8]
        ]
    ];
    
    // Serializa
    $dados = serialize($p);
    $len = strlen($dados);
    
    // Protocolo: [HEADER: 4 bytes (tamanho)] + [BODY: dados]
    // 'N' = Unsigned long (always 32 bit, big endian)
    $pacote = pack('N', $len) . $dados;
    $pacoteLen = strlen($pacote);

    if ($pacoteLen > $shmSize) die("Erro: Dados maiores que o bloco alocado.\n");
    
    // Escreve na SHM
    $written = shmop_write($shmId, $pacote, 0);
    echo "Gravados $written bytes (Header + Body) na Memória Compartilhada.\n";
    
    // IMPORTANTE: Em CLI/Windows, se fecharmos o handle e o script acabar, a memória é liberada.
    // Para teste em CLI, precisamos manter este processo rodando.
    echo "\n[DAEMON] Cache persistido na RAM.\n";
    echo ">> Mantenha este terminal ABERTO.\n";
    echo ">> Abra OUTRO terminal e rode: php src/shm_cache.php read\n"; 
    echo ">> Pressione ENTER AQUI para encerrar e limpar a memória...\n";
    
    fgets(STDIN);

    // Agora sim fechamos
    shmop_delete($shmId); // Marca para deleção quando fechar
    shmop_close($shmId);
    echo "Cache liberado. Tchau!\n";

} elseif ($modo === 'read') {
    echo "=== SHM: Reader ===\n";
    // Abre para leitura (a = access)
    $shmId = @shmop_open($shmKey, "a", 0, 0);
    
    if (!$shmId) {
        die("Erro: Cache não encontrado. Rode com 'write' primeiro.\n");
    }
    
    // 1. Ler o Header (4 bytes) para saber o tamanho exato dos dados
    $header = shmop_read($shmId, 0, 4);
    $meta = unpack('Nsize', $header);
    $dataSize = $meta['size'];

    echo "Header detectado. Tamanho do payload: " . number_format($dataSize) . " bytes.\n";
    
    $start = microtime(true);
    // 2. Ler apenas os dados (offset 4)
    $dadosRaw = shmop_read($shmId, 4, $dataSize);
    
    // Unserialize (agora sem lixo no final)
    $cache = unserialize($dadosRaw);
    $time = (microtime(true) - $start) * 1000;
    
    echo "Tempo de Leitura + Unserialize: " . number_format($time, 4) . " ms\n";
    
    echo "\n=== Dados do Cache ===\n";
    echo "Atualizado em: " . $cache['meta']['updated_at'] . " (PID Writer: {$cache['meta']['pid']})\n";
    echo "Modo Manutenção: " . ($cache['config']['maintenance_mode'] ? 'SIM' : 'NÃO') . "\n";
    echo "Taxa de Imposto: " . $cache['config']['tax_rate'] . "%\n";
    
    echo "\n[Top Produtos]\n";
    foreach ($cache['top_products'] as $id => $prod) {
        echo " - #$id: {$prod['name']} | R$ " . number_format($prod['price'], 2, ',', '.') . " (Estoque: {$prod['stock']})\n";
    }
    
    shmop_close($shmId);

} elseif ($modo === 'clear') {
    echo "=== SHM: Clear ===\n";
    $shmId = @shmop_open($shmKey, "w", 0, 0);
    if ($shmId) {
        shmop_delete($shmId);
        shmop_close($shmId);
        echo "Bloco de memória marcado para deleção.\n";
    } else {
        echo "Nada a apagar.\n";
    }
} else {
    echo "Modo inválido. Use write, read ou clear.\n";
}
