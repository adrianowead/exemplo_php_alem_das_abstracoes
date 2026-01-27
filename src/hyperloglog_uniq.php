<?php
/**
 * src/hyperloglog_uniq.php
 * Probabilistic Data Structure: HyperLogLog (HLL)
 *
 * Objetivo: Estimar a cardinalidade (números de itens únicos) em um conjunto massivo
 * usando memória constante e irrisória (alguns KB), ao invés de guardar todos os IDs.
 *
 * Cenário:
 * Você tem 1 bilhão de logs de acesso e quer saber quantos IPs ÚNICOS acessaram.
 * - Array/Set: Guardaria 1 bilhão de IPs. GBs de RAM.
 * - HLL: Estima com erro de ~2% usando ~2KB de RAM.
 *
 * Como funciona (Simplificado):
 * O HLL observa a aleatoriedade dos bits dos hashes. A probabilidade de encontrar um hash
 * que termina com N zeros consecutivos é 1/2^N. Se virmos um hash terminando em 10 zeros,
 * estatisticamente o conjunto deve ter ~2^10 = 1024 elementos para que isso tenha acontecido.
 * O HLL usa média harmônica de vários registros (buckets) para refinar essa estimativa.
 */

// Configuração do HLL
// LogLog = 2^b buckets. b=10 -> 1024 buckets.
// Mais buckets = menor erro padrão.
// Standard Error = 1.04 / sqrt(m)
const HLL_AMPLITUDE = 12; // b (2^12 = 4096 buckets, erro ~1.63%)
const HLL_BUCKETS = 1 << HLL_AMPLITUDE; // 4096

class HyperLogLog {
    private \SplFixedArray $buckets;
    private int $m;
    private float $alphaMM;

    public function __construct(int $b = HLL_AMPLITUDE) {
        $this->m = 1 << $b; // 2^b
        $this->buckets = new \SplFixedArray($this->m);
        // Inicializa com zero
        for ($i=0; $i<$this->m; $i++) $this->buckets[$i] = 0;
        
        // Constante de correção alpha (para m >= 128)
        $this->alphaMM = (0.7213 / (1 + 1.079 / $this->m)) * $this->m * $this->m;
    }

    public function add(string $value): void {
        // Hash de 32 bits (Fowler–Noll–Vo é bom e rápido, mas sha1/md5 servem)
        // Usaremos murmurhash se disponível ou crc32 como fallback (cuidado com colisão, mas é demo)
        // Vamos de hash('fnv1a32') que retorna hex, converter pra int.
        $hashHex = hash('fnv1a32', $value); 
        $hash = hexdec($hashHex);
        
        // Pega os primeiros b bits para determinar o bucket
        // (Isso é uma simplificação didática, implementações reais como Redis usam bitmasks complexas)
        $bucketIndex = $hash & ($this->m - 1); // Resto da divisão por Buckets (potência de 2)
        
        // O valor w é o restante dos bits
        // Contamos zeros à direita (trailing zeros) + 1
        // Para simular "trailing zeros" no hash original, podemos usar ffs/clz ou loop simples.
        // Vamos usar o próprio hash >> b para pegar o resto dos bits.
        $w = $hash >> HLL_AMPLITUDE;
        
        // Conta zeros à direita de w
        // Se w for 0, é max (32 bits - b)
        $rho = 1;
        if ($w == 0) {
            $rho = 32 - HLL_AMPLITUDE;
        } else {
            // Conta bits zero à direita
            while (($w & 1) == 0) {
                $rho++;
                $w >>= 1;
            }
        }
        
        // Guarda o MAIOR rho visto neste bucket
        if ($rho > $this->buckets[$bucketIndex]) {
            $this->buckets[$bucketIndex] = $rho;
        }
    }

    public function count(): int {
        // Fórmula do HLL original: E = alpha * m^2 * (sum(2^-M[j]))^-1
        $sum = 0.0;
        for ($i = 0; $i < $this->m; $i++) {
            $sum += pow(2, -1 * $this->buckets[$i]);
        }
        
        $estimate = $this->alphaMM * (1.0 / $sum);
        
        // Correção para pequenos ranges (Linear Counting)
        if ($estimate <= 2.5 * $this->m) {
            $zeros = 0;
            for ($i=0; $i<$this->m; $i++) if ($this->buckets[$i] == 0) $zeros++;
            if ($zeros != 0) {
                $estimate = $this->m * log((float)$this->m / $zeros);
            }
        }
        
        return (int)$estimate;
    }
}

// ---------------------------------------------------
// DEMO: Contando Clientes Únicos e Visitantes Únicos no Binário
// ---------------------------------------------------

ini_set('memory_limit', '128M');
$binFile = __DIR__ . '/../vendas.bin';

if (!file_exists($binFile)) {
    die("Use o etl_binario.php primeiro.\n");
}

echo "=== HyperLogLog: Estimador de Cardinalidade ===\n";
echo "Objetivo: Contar Clientes Únicos (Cardinalidade) no Dataset.\n";
echo "Método Tradicional: array_unique() -> Explode RAM se for Big Data.\n";
echo "Método HLL: Array Fixo de " . HLL_BUCKETS . " inteiros -> Memória Constante.\n\n";

$hll = new HyperLogLog();
$monitorMemoria = [];

// Leitura do Binário (Cópia da lógica do ETL)
// IIIIA28A14A40A2IA24A14dIdA8IddCC
$recordSize = 166; // Se calculamos certo antes... vamos recalcular com dummy pra ser safe
$dummy = pack('IIIIA28A14A40A2IA24A14dIdA8IddCC', 0,0,0,0,'','','','',0,'','',0,0,0,'',0,0,0,0,0);
$recordSize = strlen($dummy);

$fp = fopen($binFile, 'rb');
$bufferSize = $recordSize * 5000;
$totalLido = 0;

$start = microtime(true);

// Array "Real" para comparação (só porque nosso dataset é pequeno e podemos gastar ram pra provar)
// Em produção com 1Bi de linhas, isso não existiria.
$realUnique = [];

echo "Processando fluxo de dados...\n";

while (!feof($fp)) {
    $chunk = fread($fp, $bufferSize);
    $len = strlen($chunk);
    if ($len == 0) break;
    
    $numRecords = intdiv($len, $recordSize);
    
    for ($i=0; $i<$numRecords; $i++) {
        // Extrai apenas o ID do Cliente (Offset 12, 4 bytes) e o CPF (Offset 66, 14 bytes)
        // Estrutura: I(4) + I(4) + I(4) + I_Cliente(4) + A50 + CPF(14)...
        // Offset ID = 12. Offset CPF = 4+4+4+4+50 = 66.
        
        // Vamos contar CPF único pra ser mais divertido (string)
        // Offset CPF = 44 (após I4+I4+I4+I4+A28). Tamanho 14.
        $rawCpf = substr($chunk, ($i*$recordSize) + 44, 14);
        
        // Adiciona ao HLL
        $hll->add($rawCpf);
        
        // Adiciona ao Array Real para prova real (Ground Truth)
        // Nota: ISSO consome memória. O HLL não.
        $realUnique[$rawCpf] = true;
        
        $totalLido++;
    }
}
fclose($fp);
$duration = microtime(true) - $start;

$countHLL = $hll->count();
$countReal = count($realUnique);
$erro = abs($countHLL - $countReal);
$erroPct = ($erro / $countReal) * 100;

echo "------------------------------------------------\n";
echo "Total de Vendas Processadas: " . number_format($totalLido) . "\n";
echo "Contagem REAL (Array Unique): " . number_format($countReal) . " clientes únicos.\n";
echo "Estimativa HLL (Probabilística): " . number_format($countHLL) . " clientes únicos.\n";
echo "Erro Absoluto: $erro\n";
echo "Erro Percentual: " . number_format($erroPct, 2) . "%\n";
echo "------------------------------------------------\n";
echo "Memória Usada pelo Script PHP (incluindo Array Real de prova): " . number_format(memory_get_usage()/1024/1024, 2) . " MB\n";
unset($realUnique); // Libera o array monstro
echo "Memória do Script APÓS limpar array real (Só HLL): " . number_format(memory_get_usage()/1024/1024, 2) . " MB\n";
echo "------------------------------------------------\n";
