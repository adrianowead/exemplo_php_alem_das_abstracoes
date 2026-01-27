#!/usr/bin/php
<?php
// gerar_compras.php --semente=111 --debug=1 --saida=test_named.csv --tamanho=100mb
// Gerador de CSV de e-commerce parrudo e consistente.

ini_set('memory_limit', '50M');
set_time_limit(0);

// ==================================
// CONFIGURAÇÃO E ARGUMENTOS
// ==================================
function lerArgumentos($argv) {
    $args = [
        'tamanho' => '100mb',
        'saida'   => 'vendas.csv',
        'semente' => 12345,
        'debug'   => 0
    ];

    // Mapa de posicional para chave
    $mapaPosicional = [
        1 => 'tamanho',
        2 => 'saida',
        3 => 'semente',
        4 => 'debug'
    ];

    $posIndex = 1;
    
    foreach ($argv as $i => $arg) {
        if ($i === 0) continue; // Pula nome do script

        // Verifica se é argumento nomeado (--chave=valor)
        if (preg_match('/^--([a-zA-Z0-9_]+)=(.*)$/', $arg, $matches)) {
            $chave = $matches[1];
            $valor = $matches[2];
            
            // Mapeia chaves comuns para internas
            if ($chave === 'output' || $chave === 'arquivo') $chave = 'saida';
            if ($chave === 'size') $chave = 'tamanho';
            if ($chave === 'seed') $chave = 'semente';
            
            if (array_key_exists($chave, $args)) {
                $args[$chave] = $valor;
            }
        } 
        // Argumento posicional
        else {
            if (isset($mapaPosicional[$posIndex])) {
                $chave = $mapaPosicional[$posIndex];
                $args[$chave] = $arg;
                $posIndex++;
            }
        }
    }
    return $args;
}

$argsProcessados = lerArgumentos($argv);

$tamanhoArg     = $argsProcessados['tamanho'];
$arquivoSaida   = $argsProcessados['saida'];
$semente        = (int)$argsProcessados['semente'];
$debugProgresso = ((int)$argsProcessados['debug']) == 1;

function parseTamanho($str) {
    // Remove espaços e normaliza
    $str = trim(strtolower($str));
    // Suporte a letra única (m, k, g) ou dupla (mb, kb, gb)
    if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt])?(?:b)?$/', $str, $matches)) {
        $val = (float)$matches[1];
        $unidade = $matches[2] ?? 'm'; // Default MB
        switch($unidade) {
            case 'k': return $val * 1024;
            case 'm': return $val * 1024 * 1024;
            case 'g': return $val * 1024 * 1024 * 1024;
            case 't': return $val * 1024 * 1024 * 1024 * 1024;
        }
    }
    return 100 * 1024 * 1024; // Fallback
}

$bytesMeta           = parseTamanho($tamanhoArg);
$limiteMemoriaBytes  = parseTamanho(ini_get('memory_limit'));
$limiteSeguranca     = $limiteMemoriaBytes * 0.80; // 80% de margem pra não estourar

// Configura a semente do mal (ou do bem)
mt_srand($semente);

// Probabilidades (0.0 a 1.0)
const PROB_REUSO_CLIENTE = 0.95; // Cliente fiel volta sempre
const PROB_REUSO_PRODUTO  = 0.98; // Catálogo é limitado
const PROB_APLICAR_CUPOM   = 0.30; // 30% choram desconto

// Estruturas de Dados "In-Memory" pra manter a consistência
$clientes      = []; // [id => [...dados...]]
$produtos      = []; // [id => [...dados...]]
$cupons        = []; // [codigo => valor]
$tabelasFrete  = []; // [uf => preco_base]

// Estado global de "memória cheia"
$memoriaCheia = false;
$contadorChecagem = 0;

function verificarMemoria() {
    global $memoriaCheia, $limiteSeguranca, $contadorChecagem;
    if ($memoriaCheia) return false;
    
    // Otimização: só olha pro consumo a cada 100 chamadas
    if (++$contadorChecagem % 100 !== 0) return true;
    
    // Checagem real oficial
    if (memory_get_usage(true) > $limiteSeguranca) {
        $memoriaCheia = true;
        return false;
    }
    return true;
}

// Listas auxiliares (hardcoded pra agilizar)
$nomesM       = ['Miguel', 'Arthur', 'Gael', 'Heitor', 'Theo', 'Davi', 'Gabriel', 'Bernardo', 'Samuel', 'Joao'];
$nomesF       = ['Helena', 'Alice', 'Laura', 'Maria', 'Sophia', 'Manuela', 'Maitê', 'Liz', 'Cecília', 'Isabella'];
$sobrenomes   = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 'Pereira', 'Lima', 'Gomes'];
$adjetivosProd= ['Gamer', 'Office', 'Pro', 'Ultra', 'Slim', 'Ergonômico', 'Básico', 'Premium', 'Smart', '4K'];
$nomesProd    = ['Mouse', 'Teclado', 'Monitor', 'Cadeira', 'Mesa', 'Headset', 'Webcam', 'Microfone', 'Notebook', 'Tablet'];
$categorias   = ['Periféricos', 'Móveis', 'Computadores', 'Acessórios', 'Eletrônicos'];
$estados      = ['SP', 'RJ', 'MG', 'RS', 'PR', 'SC', 'BA', 'PE', 'CE', 'DF']; 
$dominios     = ['gmail.com', 'hotmail.com', 'outlook.com', 'uol.com.br', 'empresa.com'];

// ==================================
// FUNÇÕES GERADORAS (FACTORIES)
// ==================================

function gerarCpf() {
    $n = [];
    for($i=0; $i<11; $i++) $n[] = mt_rand(0,9);
    // Formatação visual apenas
    return sprintf('%d%d%d.%d%d%d.%d%d%d-%d%d', ...$n);
}

function gerarNome($genero) {
    global $nomesM, $nomesF, $sobrenomes;
    $primeiro = ($genero == 'M') ? $nomesM[array_rand($nomesM)] : $nomesF[array_rand($nomesF)];
    $ultimo   = $sobrenomes[array_rand($sobrenomes)] . ' ' . $sobrenomes[array_rand($sobrenomes)];
    return "$primeiro $ultimo";
}

function obterOuCriarCliente() {
    global $clientes, $estados, $dominios;
    
    // Tenta reaproveitar (se a memória deixar)
    global $memoriaCheia;
    if (!empty($clientes) && ($memoriaCheia || (mt_rand(1, 1000) / 1000 <= PROB_REUSO_CLIENTE))) {
        return $clientes[array_rand($clientes)];
    }
    
    // Se a memória tá de boa, cria um novo. Se não, já retornou reaproveitado acima.
    if (!verificarMemoria() && !empty($clientes)) { 
         return $clientes[array_rand($clientes)];
    }

    // Cria cliente zero bala
    $id = count($clientes) + 1;
    $genero = (mt_rand(0,1) ? 'M' : 'F');
    $nome = gerarNome($genero);
    $email = strtolower(str_replace(' ', '.', $nome)) . mt_rand(1,99) . '@' . $dominios[array_rand($dominios)];
    
    $cliente = [
        'id'      => $id,
        'nome'    => $nome,
        'cpf'     => gerarCpf(),
        'email'   => $email,
        'genero'  => $genero,
        'estado'  => $estados[array_rand($estados)],
        'cidade'  => 'Cidade ' . mt_rand(1, 50),
    ];
    
    $clientes[] = $cliente;
    return $cliente;
}

function obterOuCriarProduto() {
    global $produtos, $adjetivosProd, $nomesProd, $categorias;
    global $memoriaCheia;

    // Reaproveita se tiver sorte ou memória cheia
    if (!empty($produtos) && ($memoriaCheia || (mt_rand(1, 1000) / 1000 <= PROB_REUSO_PRODUTO))) {
        return $produtos[array_rand($produtos)];
    }

    if (!verificarMemoria() && !empty($produtos)) {
        return $produtos[array_rand($produtos)];
    }

    // Produto novo na área
    $id = count($produtos) + 1;
    $cat = $categorias[array_rand($categorias)];
    $nomeP = $nomesProd[array_rand($nomesProd)];
    $adj = $adjetivosProd[array_rand($adjetivosProd)];
    $nomeCompleto = "$nomeP $adj";
    
    $precoBase = mt_rand(50, 5000) + (mt_rand(0,99)/100);
    
    $produto = [
        'id'        => $id,
        'nome'      => $nomeCompleto,
        'categoria' => $cat,
        'preco'     => $precoBase,
        'peso_kg'   => mt_rand(1, 20) / 10, // 0.1 a 2.0 kg
    ];

    $produtos[] = $produto;
    return $produto;
}

function calcularFrete($estado, $peso) {
    global $tabelasFrete;
    if (!isset($tabelasFrete[$estado])) {
        $tabelasFrete[$estado] = mt_rand(10, 40); // Custo base do estado
    }
    return number_format($tabelasFrete[$estado] + ($peso * 5), 2, '.', '');
}

function obterCupom() {
    global $cupons;
    if (mt_rand(1, 1000) / 1000 > PROB_APLICAR_CUPOM) return null;

    if (empty($cupons) || mt_rand(0,10) > 8) {
        // Inventa um cupom novo
        $codigos = ['DESC', 'OFF', 'PROMO', 'VIP', 'BLACK'];
        $codigo = $codigos[array_rand($codigos)] . mt_rand(5, 50);
        $percentual = mt_rand(5, 30);
        $cupons[$codigo] = $percentual;
    }
    
    $codigo = array_rand($cupons);
    return ['codigo' => $codigo, 'percentual' => $cupons[$codigo]];
}

// ==================================
// LOOP PRINCIPAL (Onde o filho chora e a mãe não vê)
// ==================================

echo "Iniciando geração do CSV...\n";
echo "Meta: {$tamanhoArg}\n";
echo "Saída: {$arquivoSaida}\n";
echo "Seed: {$semente}\n\n";

$fp = fopen($arquivoSaida, 'w');
if (!$fp) die("Erro ao abrir arquivo pra escrita.\n");

stream_set_write_buffer($fp, 65536);

// Cabeçalho do CSV
$cabecalho = [
    'pedido_id', 'data_pedido', 'hora_pedido', 
    'cliente_id', 'cliente_nome', 'cliente_cpf', 'cliente_email', 'cliente_estado',
    'produto_id', 'produto_nome', 'categoria', 'produto_preco_base',
    'quantidade', 'valor_total_item',
    'cupom_codigo', 'cupom_desconto_pct', 'valor_final_item',
    'frete_valor', 'metodo_pagamento', 'status_pedido'
];
fwrite($fp, implode(',', $cabecalho) . "\n");
fflush($fp); // Despeja log no disco

$bytesEscritos = ftell($fp);
$totalBytes = $bytesMeta;
$linhasGeradas = 0;
$contadorPedidos = 10000;
$inicio = microtime(true);
$buffer = '';
$TAMANHO_BUFFER = 64 * 1024; // 64KB pra não pesar

while (($bytesEscritos + strlen($buffer)) < $totalBytes) {
    
    // Dados do Pedido
    $idPedido = $contadorPedidos++;
    $cliente = obterOuCriarCliente();
    
    // Data aleatória
    $timestamp = mt_rand(strtotime('-2 years'), time());
    $data = date('Y-m-d', $timestamp);
    $hora = date('H:i:s', $timestamp);
    
    $metodosPagto = ['Cartao Credito', 'Pix', 'Boleto', 'Cartao Debito'];
    $pagamento = $metodosPagto[array_rand($metodosPagto)];
    
    $listaStatus = ['Concluido', 'Pendente', 'Cancelado', 'Enviado', 'Entregue'];
    $status = $listaStatus[array_rand($listaStatus)];
    
    $cupom = obterCupom();
    
    // Quantidade de itens (carrinho cheio)
    $qtdItens = mt_rand(1, 5);
    
    for ($i = 0; $i < $qtdItens; $i++) {
        $produto = obterOuCriarProduto();
        $qtd = mt_rand(1, 3);
        
        $totalItemBase = $produto['preco'] * $qtd;
        
        // Descontinho maroto
        $percentualDesc = $cupom ? $cupom['percentual'] : 0;
        $valorDesc = $totalItemBase * ($percentualDesc / 100);
        $valorFinal = $totalItemBase - $valorDesc;
        
        // Frete
        $frete = calcularFrete($cliente['estado'], $produto['peso_kg'] * $qtd);

        // Monta a linha do CSV
        $linhaArray = [
            $idPedido, 
            $data, 
            $hora,
            $cliente['id'], 
            "\"{$cliente['nome']}\"",
            $cliente['cpf'],
            $cliente['email'],
            $cliente['estado'],
            $produto['id'],
            "\"{$produto['nome']}\"",
            $produto['categoria'],
            number_format($produto['preco'], 2, '.', ''),
            $qtd,
            number_format($totalItemBase, 2, '.', ''),
            $cupom ? $cupom['codigo'] : '',
            $percentualDesc,
            number_format($valorFinal, 2, '.', ''),
            $frete,
            $pagamento,
            $status
        ];
        
        $linhaString = implode(',', $linhaArray) . "\n";
        $len = strlen($linhaString);

        // Se o buffer encher ou a memória apertar, escreve logo
        if ((strlen($buffer) + $len) >= $TAMANHO_BUFFER || $memoriaCheia) {
            if ($buffer !== '') {
                $escrito = fwrite($fp, $buffer);
                $bytesEscritos += $escrito;
                $buffer = '';
            }
        }
        
        $buffer .= $linhaString;
        $linhasGeradas++;
        
        if ($debugProgresso && $linhasGeradas % 1000 == 0) {
            $pct = ($bytesEscritos / $totalBytes) * 100;
            echo sprintf("\r⏳ Progresso: %.4f%% - Linhas: %s - Mem: %.2fMB ", 
                $pct, 
                number_format($linhasGeradas, 0, ',', '.'),
                memory_get_usage(true)/1024/1024
            );
        }
    }
}

// Flush final do que sobrou no buffer
if (!empty($buffer)) {
    fwrite($fp, $buffer);
    $bytesEscritos += strlen($buffer);
}

fclose($fp);

$duracao = microtime(true) - $inicio;
echo "\n\n✨ Processo finalizado com sucesso!\n";
echo "Tempo total: " . number_format($duracao, 2) . "s\n";
echo "Tamanho final: " . number_format($bytesEscritos / 1024 / 1024, 2) . " MB\n";
echo "Total de linhas: " . number_format($linhasGeradas, 0, ',', '.') . "\n";
echo "Clientes únicos: " . count($clientes) . "\n";
echo "Produtos únicos: " . count($produtos) . "\n";