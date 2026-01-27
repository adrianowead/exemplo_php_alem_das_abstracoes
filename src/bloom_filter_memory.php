<?php
/**
 * src/bloom_filter_memory.php
 * Probabilistic Data Structure: Bloom Filter
 *
 * Objetivo: Verificar se um ID de Cliente existe na base SEM carregar a base na memória
 * e SEM usar banco de dados.
 *
 * O que é um Bloom Filter?
 * É um array de bits (0 ou 1). Quando adicionamos um item, rodamos N funções de hash
 * e marcamos os bits correspondentes como 1.
 * Para checar se existe, rodamos as mesmas hashes.
 * - Se algum bit for 0: O item COM CERTEZA não existe.
 * - Se todos forem 1: O item TALVEZ exista (pode ser colisão, "falso positivo").
 *
 * Vantagem:
 * - Memória constante (ex: 512KB para milhões de registros).
 * - Velocidade brutal (O(k) onde k é numero de hashes).
 */

ini_set('memory_limit', '128M');

$binFile = __DIR__ . '/../vendas.bin';

if (!file_exists($binFile)) {
    die("Erro: Arquivo '$binFile' não encontrado.\nRode: php src/etl_binario.php vendas.csv vendas.bin\n");
}

class BloomFilter {
    private string $bitset;
    private int $sizeBits;
    private int $numHashes;

    public function __construct(int $sizeBytes, int $numHashes = 3) {
        // Inicializa string com bytes NULL (todos os bits 0)
        $this->bitset = str_repeat("\0", $sizeBytes);
        $this->sizeBits = $sizeBytes * 8;
        $this->numHashes = $numHashes;
    }

    public function add(string $key): void {
        foreach ($this->getHashes($key) as $hash) {
            $position = $hash % $this->sizeBits;
            $bytePos = (int)($position / 8);
            $bitPos = $position % 8;
            
            // Seta o bit específico usando bitwise OR
            // ord() pega valor numérico, | é o OR, chr() volta pra char
            $currentByteVal = ord($this->bitset[$bytePos]);
            $newByteVal = $currentByteVal | (1 << $bitPos);
            $this->bitset[$bytePos] = chr($newByteVal);
        }
    }

    public function exists(string $key): bool {
        foreach ($this->getHashes($key) as $hash) {
            $position = $hash % $this->sizeBits;
            $bytePos = (int)($position / 8);
            $bitPos = $position % 8;

            $byteVal = ord($this->bitset[$bytePos]);
            
            // Verifica se o bit está ligado usando bitwise AND
            if (($byteVal & (1 << $bitPos)) === 0) {
                return false; // Absolutamente certeza que não existe
            }
        }
        return true; // Provavelmente existe
    }

    private function getHashes(string $key): array {
        $hashes = [];
        // Hash 1: CRC32
        $hashes[] = crc32($key);
        // Hash 2: Adler32 (rápido)
        $hashes[] = hash('adler32', $key, false); // retorna hex, precisa converter? 
                                                  // na vdd adler32 retorna hex string. crc32 retorna int.
                                                  // vamos usar crc32 modificados pra performance pura (simulando k hashes)
        
        // Truque de Double Hashing para gerar K hashes a partir de 2 apenas
        // h(i) = (h1 + i * h2) % m
        $h1 = crc32($key);
        $h2 = crc32('salt' . $key);

        $results = [];
        for ($i = 0; $i < $this->numHashes; $i++) {
            // abs para garantir positivo
            $results[] = abs($h1 + ($i * $h2));
        }
        
        return $results;
    }
    
    public function getMemoryUsage(): int {
        return strlen($this->bitset);
    }
}

// -----------------------------------------------
// Configuração do Filtro
// -----------------------------------------------
// 1MB = 8 milhões de bits.
// Para 1 milhão de itens, m=8M bits, k=3 hashes -> False Positive Rate ~3%
$filterSize = 1024 * 1024; // 1 MB de RAM apenas!
$bf = new BloomFilter($filterSize, 3);

echo "=== Bloom Filter: Probabilidade em Ação ===\n";
echo "Alocando Filtro: " . number_format($filterSize / 1024, 2) . " KB\n";
echo "Lendo arquivo binário para treinar o filtro...\n";

// -----------------------------------------------
// Treinamento (Leitura do Binário)
// -----------------------------------------------
$fp = fopen($binFile, 'rb');
// Definição do formato binário IDENTICA ao etl_binario.php
// IIIIA28A14A40A2IA24A14dIdA8IddCC
// O Cliente ID é o 4º Inteiro (offsets: 0, 4, 8, 12).
// Então Cliente ID está no offset 12 e tem tamanho 4 bytes.
// O tamanho total do registro é... calcular de novo ou usar constante.
$dummy = pack('IIIIA28A14A40A2IA24A14dIdA8IddCC', 0,0,0,0,'','','','',0,'','',0,0,0,'',0,0,0,0,0);
$recordSize = strlen($dummy);
$bufferSize = $recordSize * 10000; // Ler 10k registros por vez

$count = 0;
$clientesReais = []; // Guardar alguns pra testar depois (só IDs, pra não gastar RAM)

$start = microtime(true);
while (!feof($fp)) {
    $chunk = fread($fp, $bufferSize);
    $len = strlen($chunk);
    if ($len == 0) break;
    
    $numRecords = (int)($len / $recordSize);
    
    for ($i = 0; $i < $numRecords; $i++) {
        // Otimização: Não dar unpack em tudo. Ler só os bytes do ID.
        // Offset 12, tamanho 4.
        $offset = ($i * $recordSize) + 12;
        // Pega 4 bytes crus
        $rawId = substr($chunk, $offset, 4);
        // Desempacota só o ID
        $data = unpack('Iid', $rawId);
        $clienteId = (string)$data['id'];
        
        $bf->add($clienteId);
        
        // Guarda amostra pra teste
        if ($count % 1000 == 0 && count($clientesReais) < 10) {
            $clientesReais[] = $clienteId;
        }
        $count++;
    }
}
fclose($fp);
$trainTime = microtime(true) - $start;

echo "Treinamento concluído!\n";
echo "Registros processados: " . number_format($count) . "\n";
echo "Tempo de treino: " . number_format($trainTime, 4) . "s\n";
echo "-----------------------------------------------\n";

// -----------------------------------------------
// Teste de Verificação
// -----------------------------------------------
echo "Testando Clientes que EXISTEM (Esperado: Sim para todos)\n";
foreach ($clientesReais as $id) {
    $exists = $bf->exists($id);
    echo "ID [$id]: " . ($exists ? "Encontrado (✅)" : "NÃO ENCONTRADO (❌ ERRO)") . "\n";
}

echo "-----------------------------------------------\n";
echo "Testando Clientes FAKE (Esperado: Não, mas pode haver falsos positivos)\n";
$falsosPositivos = 0;
$testes = 100;

for ($i = 0; $i < $testes; $i++) {
    $fakeId = (string)(999999000 + $i); // IDs muito altos que não existem na base
    if ($bf->exists($fakeId)) {
        // echo "ID [$fakeId]: Falso Positivo detectado!\n";
        $falsosPositivos++;
    }
}

echo "Total de Testes Fakes: $testes\n";
echo "Falsos Positivos: $falsosPositivos (" . ($falsosPositivos/$testes*100) . "%)\n";
echo "\nConclusão: Conseguimos verificar a existência com certeza de 'Não' sem consultar banco/disco,\n";
echo "usando apenas " . number_format($bf->getMemoryUsage() / 1024, 2) . " KB de RAM.\n";
