<?php
/**
 * src/trie_autocomplete.php
 * Estrutura de Dados Avançada: Trie (Árvore de Prefixos)
 *
 * Objetivo: Realizar buscas de autocomplete (ex: "mous...") em O(k) onde k é o tamanho da palavra,
 * independente de termos 10 ou 10 milhões de produtos.
 *
 * Busca tradicional em Array (LIKE %term%): O(n) - Tem que iterar tudo.
 * Busca em Trie: O(k) - Navega apenas os nós da palavra pesquisada.
 */

ini_set('memory_limit', '256M');

class TrieNode {
    public $children = [];
    public $isEndOfWord = false;
    public $data = []; // Pode guardar o produto completo
}

class Trie {
    private $root;

    public function __construct() {
        $this->root = new TrieNode();
    }

    public function insert(string $word, $extraData = null) {
        $node = $this->root;
        $word = strtolower(trim($word));
        
        for ($i = 0; $i < strlen($word); $i++) {
            $char = $word[$i];
            if (!isset($node->children[$char])) {
                $node->children[$char] = new TrieNode();
            }
            $node = $node->children[$char];
        }
        $node->isEndOfWord = true;
        // Se já existe, acumula (ex: dois produtos com mesmo nome mas preços diferentes)
        if ($extraData) {
            $node->data[] = $extraData;
        }
    }

    // Retorna todas as palavras que começam com o prefixo
    public function search(string $prefix): array {
        $node = $this->root;
        $prefix = strtolower(trim($prefix));
        
        // 1. Navega até o fim do prefixo
        for ($i = 0; $i < strlen($prefix); $i++) {
            $char = $prefix[$i];
            if (!isset($node->children[$char])) {
                return []; // Prefixo não existe
            }
            $node = $node->children[$char];
        }
        
        // 2. Coleta todas as palavras abaixo deste nó (DFS)
        $results = [];
        $this->collect($node, $prefix, $results);
        return $results;
    }
    
    // Depth First Search para coletar sufixos
    private function collect(TrieNode $node, string $dictWord, array &$results) {
        if ($node->isEndOfWord) {
            // Unificando com os dados payload (ex: preço)
            foreach ($node->data as $item) {
                $results[] = [
                    'term' => ucfirst($dictWord),
                    'meta' => $item
                ];
            }
            if (empty($node->data)) {
                 $results[] = ['term' => ucfirst($dictWord)];
            }
        }
        
        foreach ($node->children as $char => $childNode) {
            $this->collect($childNode, $dictWord . $char, $results);
        }
    }
}

// ---------------------------------------------------
// Carregando Produtos do Binário
// ---------------------------------------------------
$binFile = __DIR__ . '/../vendas.bin';
if (!file_exists($binFile)) die("Rode o ETL primeiro.\n");

$trie = new Trie();
$produtosCarregados = 0;

echo "=== Trie Data Structure: Otimizando Autocomplete ===\n";
echo "Construindo índice em memória da árvore de prefixos (Isso pode custar RAM)...\n";

$startLoad = microtime(true);
$fp = fopen($binFile, 'rb');

// Config do Binário
$dummy = pack('IIIIA50A14A50A2IA60A20dIdA10IddCC', 0,0,0,0,'','','','',0,'','',0,0,0,'',0,0,0,0,0);
$recordSize = strlen($dummy);
$bufferSize = $recordSize * 5000;

// Set para não inserir duplicados desnecessários no demo
$seen = [];

while (!feof($fp)) {
    $chunk = fread($fp, $bufferSize);
    if (strlen($chunk) == 0) break;
    
    $numRecords = intdiv(strlen($chunk), $recordSize);
    
    for ($i=0; $i<$numRecords; $i++) {
        // Offset 136 = Produto Nome (A60)
        // Offset 208 = Preco (double) -> I(4)*4 +50+14+50+2 + I(4) + A60 + A20 = 16+116+4+60+20 = 216?
        // Vamos recalcular offset do Preço:
        // 16 (Ints) + 116 (Clientes) + 4 (PrdID) + 60 (Prd) + 20 (Cat) = 216.
        // Logo preço começa em 216.
        
        $offsetNome = ($i * $recordSize) + 136;
        $offsetPreco = ($i * $recordSize) + 216;
        
        $rawNome = substr($chunk, $offsetNome, 60);
        $rawPreco = substr($chunk, $offsetPreco, 8); // double = 8 bytes
        
        $nome = trim(str_replace("\0", '', $rawNome)); // Remove padding NULL
        
        // Decodifica preço
        $dadosPreco = unpack('dval', $rawPreco);
        $preco = $dadosPreco['val'];
        
        // Evita triplicar memória com strings repetidas (simples dedup)
        // O Trie em si já compprime prefixos comuns (Mouse Gamer X e Mouse Gamer Y compartilham 'Mouse Gamer ')
        $trie->insert($nome, round($preco, 2));
        $produtosCarregados++;
    }
}
fclose($fp);
$loadTime = microtime(true) - $startLoad;

echo "Índice construído!\n";
echo "Produtos processados: " . number_format($produtosCarregados) . "\n";
echo "Tempo de indexação: " . number_format($loadTime, 4) . "s\n";
echo "Memória atual: " . number_format(memory_get_usage()/1024/1024, 2) . " MB\n";
echo "-----------------------------------------------\n";

// ---------------------------------------------------
// Teste de Performance: Array Search vs Trie Search
// ---------------------------------------------------

$termos = ['Mou', 'Tecl', 'Gam', 'Off', 'Monit', 'Lap'];

foreach ($termos as $termo) {
    echo "🔍 Buscando prefixo: ['$termo']\n";
    
    $start = microtime(true);
    $resultados = $trie->search($termo);
    $time = (microtime(true) - $start) * 1000; // ms
    
    // Limita exibição
    $qtd = count($resultados);
    echo "   Encontrados: $qtd registros em " . number_format($time, 4) . "ms\n";
    
    // Mostra top 3
    for ($j=0; $j<min(3, $qtd); $j++) {
        echo "   -> " . $resultados[$j]['term'] . " (R$ " . $resultados[$j]['meta'] . ")\n";
    }
    if ($qtd > 3) echo "   ... (mais " . ($qtd-3) . ")\n";
    echo "\n";
}

echo "Nota: Com um Array simples com 'strpos', teríamos que percorrer todos os " . number_format($produtosCarregados) . " itens para cada busca.\n";
echo "Com Trie, percorremos apenas os caracteres da palavra pesquisada (ex: 3 nós para 'Mou').\n";
