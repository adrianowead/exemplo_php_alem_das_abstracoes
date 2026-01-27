<?php
/**
 * src/stream_vendas.php
 * Stream Wrapper Customizado: vendas://
 *
 * Objetivo: Demonstrar como abstrair a complexidade de arquivos binários
 * criando um protocolo próprio que converte binário para texto (CSV simulado)
 * on-the-fly.
 *
 * Quando usar?
 * Para permitir que funções padrões do PHP (fgetcsv, file, file_get_contents)
 * leiam formatos proprietários/complexos de forma transparente.
 */

// Definição do Formato Binário (Cópia da Constant do ETL - ATUALIZADO)
// IIIIA28A14A40A2IA24A14dIdA8IddCC
const PACK_FMT = 'Ipedido_id/Its_data/Iseg_hora/Icliente_id/A28nome/A14cpf/A40email/A2uf/Iprod_id/A24prod/A14cat/dpreco/Iqtd/dtotal/A8cupom/Icupom_pct/dvalor_final/dfrete/Cpagto/Cstatus';

class VendasStream {
    private $fp;
    private $recordSize;
    private $buffer = '';

    public function stream_open($path, $mode, $options, &$opened_path) {
        // Parse manual da URL para suportar caminhos absolutos no Windows (ex: vendas://C:\...)
        // parse_url() falha com schemes customizados + drive letters.
        $protocol = 'vendas://';
        if (strpos($path, $protocol) === 0) {
            $localPath = substr($path, strlen($protocol));
        } else {
            $localPath = $path;
        }

        if (!file_exists($localPath)) {
            trigger_error("Arquivo não encontrado: $localPath", E_USER_WARNING);
            return false;
        }

        $this->fp = fopen($localPath, 'rb');
        
        // Calcula tamanho do registro binário (formato compactado)
        $dummy = pack('IIIIA28A14A40A2IA24A14dIdA8IddCC', 0,0,0,0,'','','','',0,'','',0,0,0,'',0,0,0,0,0);
        $this->recordSize = strlen($dummy);

        return true;
    }

    public function stream_read($count) {
        // O PHP pede $count bytes.
        // Precisamos retornar *no máximo* $count bytes para evitar warnings de overflow.
        // Implementamos um buffer interno para guardar o que ler "a mais".
        
        while (strlen($this->buffer) < $count && !feof($this->fp)) {
            $binaryData = fread($this->fp, $this->recordSize);
            if (strlen($binaryData) < $this->recordSize) break; // EOF inesperado ou fim real

            // Unpack nos dados
            $data = unpack(PACK_FMT, $binaryData);
            
            // Corrige timestamp/hora
            $dataStr = date('Y-m-d', $data['ts_data']);
            
            // Monta linha CSV simulada
            $csvLine = sprintf(
                "%d,%s,%s,%.2f\n",
                $data['pedido_id'],
                $dataStr,
                trim($data['nome']),
                $data['valor_final']
            );
            
            $this->buffer .= $csvLine;
        }
        
        // Retorna o pedaço exato que o PHP pediu
        $chunk = substr($this->buffer, 0, $count);
        
        // Guarda o resto para a próxima chamada
        $this->buffer = substr($this->buffer, $count);
        
        return $chunk;
    }

    public function stream_eof() {
        return feof($this->fp);
    }
    
    public function stream_stat() {
        return fstat($this->fp);
    }
    
    public function stream_close() {
        fclose($this->fp);
    }
}

// 1. Registra o protocolo
stream_wrapper_register("vendas", "VendasStream")
    or die("Falha ao registrar protocolo vendas://");

$binFile = __DIR__ . '/../vendas.bin';

if (!file_exists($binFile)) {
    die("Arquivo vendas.bin não existe. Rode o ETL primeiro.\n");
}

echo "=== Stream Wrapper Demo (vendas://) ===\n";
echo "Lendo arquivo *BINÁRIO* como se fosse *TEXTO* via protocolo customizado.\n";
echo "URL: vendas://$binFile\n\n";

// 2. Uso Transparente
// O PHP acha que é um arquivo normal.
$fp = fopen("vendas://$binFile", "r");

$i = 0;
echo "--- Primeiras 10 linhas (Virtual CSV) ---\n";
echo "ID,DATA,NOME,VALOR\n";

while (($line = fgets($fp)) !== false) {
    echo $line;
    $i++;
    if ($i >= 10) break;
}
fclose($fp);

echo "...\n";
echo "\nNota: O arquivo original é binário ilegível. O wrapper fez a tradução on-the-fly.\n";
