<?php
/**
 * src/etl_binario.php
 * Converte CSV para Binário de Largura Fixa (Fixed-Width).
 *
 * NOTA MENTAL: O CSV é um lixo para processar. É texto solto.
 * A meta aqui é transformar esse caos em bytes alinhados.
 * Se eu conseguir alinhar tudo, consigo fazer seek(O(1)).
 * Além disso, se eu compactar enums em bytes, o I/O cai pela metade.
 */

ini_set('memory_limit', '512M');

$csvFile = $argv[1] ?? 'vendas.csv';
$binFile = $argv[2] ?? 'vendas.bin';

if (!file_exists($csvFile)) {
    die("Erro: Cadê o arquivo '$csvFile'? Me ajuda a te ajudar.\n");
}

// ---------------------------------------------------------------
// 1. DEFINIÇÃO DO FORMATO (A ARTE DA COMPACTAÇÃO)
// ---------------------------------------------------------------
// Nota para mim mesmo:
// Strings (A) ocupam muito espaço. Trocar tudo que der por Int (I) ou Char (C).
// Timestamp unix (4 bytes) é melhor que '2024-01-01' (10 bytes).
// Status e Pagamento cabem num Byte (C) tranquilo.
// CPF cabe num Int64, mas o PHP 32bits chora... vamos de string limpa (A11) ou mantém A14.
// TAMANHOS OTIMIZADOS: Analisamos o CSV real e ajustamos para o tamanho máximo + margem pequena.
// Nome max=27 -> A28 | Email max=39 -> A40 | Produto max=22 -> A24 | Categoria max=12 -> A14 | Cupom max=7 -> A8
//
// FORMATO COMPACTO:
// I (id) + I (ts_data) + I (seg_hora) + I (cli_id) + A28 + A14 + A40 + A2 + I + A24 + A14 + d + I + d + A8 + I + d + d + C (pagto) + C (status)

const FORMATO_PACK = 'IIIIA28A14A40A2IA24A14dIdA8IddCC';
const FORMATO_UNPACK = 'Ipedido_id/Its_data/Iseg_hora/Icliente_id/A28nome/A14cpf/A40email/A2uf/Iprod_id/A24prod/A14cat/dpreco/Iqtd/dtotal/A8cupom/Icupom_pct/dvalor_final/dfrete/Cpagto/Cstatus';

// Mapeamentos para transformar Texto -> Byte (Lookup simples)
$mapaPagto = [
    'Cartao Credito' => 1, 'Pix' => 2, 'Boleto' => 3, 'Cartao Debito' => 4,
    // Fallback
    '' => 0
];
$mapaStatus = [
    'Concluido' => 1, 'Pendente' => 2, 'Cancelado' => 3, 'Enviado' => 4, 'Entregue' => 5,
    '' => 0
];

// Calculamos o tamanho na unha pra garantir
$dummy = pack(FORMATO_PACK, 0,0,0,0,'','','','',0,'','',0,0,0,'',0,0,0,0,0);
$recordSize = strlen($dummy);

echo "=== ETL: Engenharia de Dados Binários (Otimizado) ===\n";
echo "Tamanho do Registro: $recordSize bytes (Compactado)\n";

// ---------------------------------------------------------------
// 2. CONVERSÃO (ESCRITA COM INTELIGÊNCIA)
// ---------------------------------------------------------------
$fpCsv = fopen($csvFile, 'r');
$fpBin = fopen($binFile, 'wb');
fgetcsv($fpCsv); // Pula cabeçalho, quem lê cabeçalho é ser humano.

$count = 0;
$start = microtime(true);

echo "Convertendo CSV para Binário (Aplicando Compressão Lógica)...";

while (($row = fgetcsv($fpCsv)) !== false) {
    if (count($row) < 20) continue;

    // Nota: Aqui a mágica acontece. Conversão de tipos "On the Fly".
    // Data (YYYY-MM-DD) -> Timestamp
    $tsData = strtotime($row[1]); 
    // Hora (HH:MM:SS) -> Segundos do dia (0-86400)
    $partesHora = explode(':', $row[2]);
    $segHora = ($partesHora[0] * 3600) + ($partesHora[1] * 60) + $partesHora[2];

    // Enums -> Bytes
    $codPagto = $mapaPagto[$row[18]] ?? 0;
    $codStatus = $mapaStatus[$row[19]] ?? 0;

    $dados = [
        (int)$row[0],               // I: pedido_id
        $tsData,                    // I: Timestamp Data
        $segHora,                   // I: Segundos Hora
        (int)$row[3],               // I: cliente_id
        substr($row[4], 0, 28),     // A28: nome
        substr($row[5], 0, 14),     // A14: cpf
        substr($row[6], 0, 40),     // A40: email
        substr($row[7], 0, 2),      // A2: uf
        (int)$row[8],               // I: prod_id
        substr($row[9], 0, 24),     // A24: prod
        substr($row[10], 0, 14),    // A14: cat
        (float)$row[11],            // d: preco
        (int)$row[12],              // I: qtd
        (float)$row[13],            // d: total
        substr($row[14], 0, 8),     // A8: cupom
        (int)$row[15],              // I: cupom_pct
        (float)$row[16],            // d: valor_final
        (float)$row[17],            // d: frete
        $codPagto,                  // C: pagto (1 byte!)
        $codStatus                  // C: status (1 byte!)
    ];

    fwrite($fpBin, pack(FORMATO_PACK, ...$dados));
    $count++;
    
    // Feedback visual pra não achar que travou
    if ($count % 50000 === 0) echo ".";
}

fclose($fpCsv);
fclose($fpBin);

$timeWrite = microtime(true) - $start;
echo "\nEscrita finalizada em " . number_format($timeWrite, 4) . "s\n";

// ---------------------------------------------------------------
// 3. BENCHMARK DE LEITURA (A PROVA DOS NOVE)
// ---------------------------------------------------------------
echo "\n=== Comparativo de Performance (Leitura Sequencial) ===\n";

// TESTE 1: CSV Tradicional (O jeito lento)
$start = microtime(true);
$fp = fopen($csvFile, 'r');
fgetcsv($fp);
$sumCsv = 0;
while ($row = fgetcsv($fp)) { $sumCsv += (float)($row[16] ?? 0); }
fclose($fp);
$timeCsv = microtime(true) - $start;
echo "CSV: " . number_format($timeCsv, 4) . "s | Soma: " . number_format($sumCsv, 2) . "\n";

// TESTE 2: Binário com Buffer (O jeito 'Sênior')
// O segredo aqui é ler blocos GRANDES (chunking) para diminuir syscalls.
// Ler byte a byte é pedir pra CPU sofrer à toa.
$start = microtime(true);
$fp = fopen($binFile, 'rb');
$sumBin = 0;

// 4MB de buffer é um sweet spot pra maioria dos discos modernos
$bufferSize = $recordSize * 15000; 

while (!feof($fp)) {
    $chunk = fread($fp, $bufferSize);
    $chunkLen = strlen($chunk);
    if ($chunkLen === 0) break;

    // Quantos registros inteiros cabem nesse chunk?
    $numRecords = (int)($chunkLen / $recordSize);
    
    // Loop manual no binário bruto.
    // Unpack consome CPU, então só faço nos campos que preciso.
    // Offset do valor_final:
    // I(4)+I(4)+I(4)+I(4)+A50(50)+A14(14)+A50(50)+A2(2)+I(4)+A60(60)+A20(20)+d(8)+I(4)+d(8)+A10(10)+I(4) = 246 bytes
    // O valor_final (double) começa no byte 246 (0-indexed).
    // Validar offset:
    // I,I,I,I = 16
    // A50, A14, A50, A2 = 116
    // I = 4
    // A60, A20 = 80
    // d, I, d, A10, I = 8+4+8+10+4 = 34
    // Total antes: 16+116+4+80+34 = 250 bytes?
    // Vamos confiar no unpack por enquanto pra não errar conta de cabeça e bugar o demo.
    
    for ($i = 0; $i < $numRecords; $i++) {
        // Extrai apenas o pedaço deste registro
        $raw = substr($chunk, $i * $recordSize, $recordSize);
        // Unpack cria array associativo, overhead aceitável pela legibilidade
        $data = unpack(FORMATO_UNPACK, $raw);
        $sumBin += $data['valor_final'];
    }
}
fclose($fp);
$timeBin = microtime(true) - $start;

echo "BIN: " . number_format($timeBin, 4) . "s | Soma: " . number_format($sumBin, 2) . "\n";

// ---------------------------------------------------------------
// 4. CONCLUSÃO
// ---------------------------------------------------------------
$speedup = $timeBin > 0 ? $timeCsv / $timeBin : 0;
$sizeCsv = filesize($csvFile);
$sizeBin = filesize($binFile);
$ratio = $sizeCsv > 0 ? ($sizeBin / $sizeCsv) * 100 : 0;

echo "\n--- Análise de Engenharia ---\n";
echo "Velocidade: " . number_format($speedup, 2) . "x mais rápido que o CSV.\n";
echo "Tamanho Original (CSV): " . number_format($sizeCsv / 1024 / 1024, 2) . " MB\n";
echo "Tamanho Final (Bin):    " . number_format($sizeBin / 1024 / 1024, 2) . " MB (" . number_format($ratio, 1) . "% do original)\n";
echo "Vantagem: Menos I/O de disco, menos parsing de string, CPU feliz.\n";

