<?php
// src/weakmap_benchmark.php
// Benchmark: WeakMap vs Array para caches associados a objetos
//
// Em long-running processes (workers, daemons), associar dados a objetos usando arrays
// cria referencias fortes que impedem o Garbage Collector de liberar memoria.
// WeakMap (PHP 8.0+) resolve isso: quando o objeto nao tem mais referencias externas,
// ele e seus dados associados sao liberados automaticamente.
//
// Uso:
//   php src/weakmap_benchmark.php

declare(strict_types=1);

ini_set('memory_limit', '512M');

echo "=================================================\n";
echo "  Benchmark: WeakMap vs Array Cache\n";
echo "  Simulacao: Memory Leak em Long-Running Process\n";
echo "=================================================\n\n";

// ================================
// PARTE 1: DEMONSTRACAO DO CONCEITO
// ================================
echo "-------------------------------------\n";
echo " PARTE 1: Entendendo Weak References\n";
echo "-------------------------------------\n\n";

echo "WeakMap cria referencias FRACAS a objetos. Quando um objeto nao tem mais\n";
echo "referencias FORTES, ele pode ser coletado pelo GC mesmo estando no WeakMap.\n\n";

echo "Exemplo Pratico:\n";
echo str_repeat("-", 75) . "\n\n";

// Classe simples para demonstracao
class Usuario {
    public function __construct(public readonly string $nome) {}
}

// TESTE COM ARRAY (referencia forte)
echo "[Teste 1] Array tradicional (referencia FORTE):\n\n";

$cacheArray = [];
$usuario1 = new Usuario('Alice');

$cacheArray[spl_object_id($usuario1)] = ['role' => 'admin', 'login_at' => time()];

echo "  1. Criado objeto Usuario('Alice')\n";
echo "  2. Adicionado ao cache: " . count($cacheArray) . " entrada(s)\n";

unset($usuario1);  // Remove a referencia ao objeto
gc_collect_cycles();

echo "  3. Apos unset(\$usuario1) e GC:\n";
echo "     - Objeto Usuario foi destruido? SIM (nao ha referencias)\n";
echo "     - Cache ainda tem a entrada? SIM (referencia forte persiste!)\n";
echo "     - Tamanho do cache: " . count($cacheArray) . " entrada(s)\n";
echo "     - RESULTADO: MEMORY LEAK - dados orfaos no cache\n\n";

unset($cacheArray);

// TESTE COM WEAKMAP (referencia fraca)
echo "[Teste 2] WeakMap (referencia FRACA):\n\n";

$cacheWeak = new WeakMap();
$usuario2 = new Usuario('Bob');

$cacheWeak[$usuario2] = ['role' => 'user', 'login_at' => time()];

echo "  1. Criado objeto Usuario('Bob')\n";
echo "  2. Adicionado ao WeakMap: " . count($cacheWeak) . " entrada(s)\n";

unset($usuario2);  // Remove a referencia ao objeto
gc_collect_cycles();

echo "  3. Apos unset(\$usuario2) e GC:\n";
echo "     - Objeto Usuario foi destruido? SIM (nao ha referencias)\n";
echo "     - WeakMap ainda tem a entrada? NAO (referencia fraca foi limpa!)\n";
echo "     - Tamanho do WeakMap: " . count($cacheWeak) . " entrada(s)\n";
echo "     - RESULTADO: SEM LEAK - memoria liberada automaticamente\n\n";

echo "CONCLUSAO: WeakMap permite ao GC liberar objetos mesmo que estejam\n";
echo "           sendo usados como chaves no mapa. Perfeito para caches!\n";

unset($cacheWeak);

// ================================
// PARTE 2: BENCHMARK DE MEMORY LEAK
// ================================
echo "\n\n-------------------------------------\n";
echo " PARTE 2: Simulacao de Memory Leak (50.000 objetos)\n";
echo "-------------------------------------\n\n";

// ================================
// CENARIO: Worker que processa entidades e mantem cache de metadata
// Em producao: Doctrine entities, conexoes com metadata, handlers com contexto
// ================================

$iterations = 50000;

// Classe simples para simular entidades/objetos de dominio
class Entidade {
    public function __construct(
        public readonly int $id,
        public readonly string $tipo
    ) {}
}

// ================================
// TESTE 1: Cache usando Array (VAZAMENTO DE MEMORIA)
// ================================
echo "[TESTE 1] Cache com Array tradicional (provoca memory leak)\n\n";
echo "  Cenario:\n";
echo "    - Processar $iterations objetos em batches de 1000\n";
echo "    - Cada batch e liberado apos processamento\n";
echo "    - Cache usando spl_object_id() como chave\n\n";

gc_collect_cycles();
$memInicial = memory_get_usage(true);
$memPico = 0;

$cacheArray = [];
$objetosAtivos = [];

for ($i = 0; $i < $iterations; $i++) {
    // Cria objeto (simula fetch do banco)
    $obj = new Entidade($i, 'produto');
    
    // Associa metadata ao objeto (simula calculo de atributos derivados)
    // Problema: usamos spl_object_id, que e um int. Mesmo apos object ser destruido,
    // o cache mantem a entrada indefinidamente.
    $cacheArray[spl_object_id($obj)] = [
        'hash' => md5(serialize($obj)),
        'processado_em' => microtime(true),
        'flags' => random_int(0, 255)
    ];
    
    // A cada 1000 objetos, "libera" alguns (simula fim de processamento de batch)
    if ($i % 1000 === 999) {
        // Limpa referencias aos objetos, mas o cache MANTEM as entradas!
        unset($objetosAtivos);
        $objetosAtivos = [];
        gc_collect_cycles();
    } else {
        $objetosAtivos[] = $obj;
    }
    
    // Monitora pico de memoria
    $memAtual = memory_get_usage(true);
    if ($memAtual > $memPico) {
        $memPico = $memAtual;
    }
}

$memFinalArray = memory_get_usage(true);
$tamanhoCache = count($cacheArray);

echo "  Resultados:\n";
echo "    Entradas no cache: " . number_format($tamanhoCache) . " (TODAS RETIDAS!)\n";
echo "    Memoria inicial: " . number_format($memInicial / 1024 / 1024, 2) . " MB\n";
echo "    Memoria final:   " . number_format($memFinalArray / 1024 / 1024, 2) . " MB\n";
echo "    Pico de memoria: " . number_format($memPico / 1024 / 1024, 2) . " MB\n";
echo "    VAZAMENTO:       " . number_format(($memFinalArray - $memInicial) / 1024 / 1024, 2) . " MB\n";

// Limpa para proximo teste
unset($cacheArray, $objetosAtivos);
gc_collect_cycles();
sleep(1); // Permite GC completo

// ================================
// TESTE 2: Cache usando WeakMap (SEM VAZAMENTO)
// ================================
echo "\n[TESTE 2] Cache com WeakMap (previne memory leak)\n\n";
echo "  Cenario:\n";
echo "    - Processar $iterations objetos em batches de 1000\n";
echo "    - Cada batch e liberado apos processamento\n";
echo "    - Cache usando WeakMap (referencias fracas)\n\n";

gc_collect_cycles();
$memInicial = memory_get_usage(true);
$memPico = 0;

$cacheWeak = new WeakMap();
$objetosAtivos = [];

for ($i = 0; $i < $iterations; $i++) {
    // Cria objeto (simula fetch do banco)
    $obj = new Entidade($i, 'produto');
    
    // Associa metadata ao objeto usando WeakMap
    // Quando $obj nao tiver mais referencias, a entrada e removida automaticamente
    $cacheWeak[$obj] = [
        'hash' => md5(serialize($obj)),
        'processado_em' => microtime(true),
        'flags' => random_int(0, 255)
    ];
    
    // A cada 1000 objetos, "libera" o batch (simula fim de processamento)
    if ($i % 1000 === 999) {
        // Agora, quando limpamos as referencias, o WeakMap REMOVE as entradas!
        unset($objetosAtivos);
        $objetosAtivos = [];
        gc_collect_cycles();
    } else {
        $objetosAtivos[] = $obj;
    }
    
    // Monitora pico de memoria
    $memAtual = memory_get_usage(true);
    if ($memAtual > $memPico) {
        $memPico = $memAtual;
    }
}

$memFinalWeak = memory_get_usage(true);
$tamanhoCache = count($cacheWeak);

echo "  Resultados:\n";
echo "    Entradas no cache: " . number_format($tamanhoCache) . " (apenas ativos!)\n";
echo "    Memoria inicial: " . number_format($memInicial / 1024 / 1024, 2) . " MB\n";
echo "    Memoria final:   " . number_format($memFinalWeak / 1024 / 1024, 2) . " MB\n";
echo "    Pico de memoria: " . number_format($memPico / 1024 / 1024, 2) . " MB\n";
echo "    VAZAMENTO:       " . number_format(($memFinalWeak - $memInicial) / 1024 / 1024, 2) . " MB (controlado!)\n";

// ================================
// PARTE 3: ANALISE E CASOS DE USO
// ================================
echo "\n\n-------------------------------------\n";
echo " PARTE 3: Analise Comparativa\n";
echo "-------------------------------------\n\n";

$leakArray = $memFinalArray - $memInicial;
$leakWeak = $memFinalWeak - $memInicial;
$economia = $leakArray - $leakWeak;

echo "CONSUMO DE MEMORIA:\n\n";
echo "  Array tradicional:  " . number_format($leakArray / 1024 / 1024, 2) . " MB de vazamento\n";
echo "  WeakMap:            " . number_format($leakWeak / 1024 / 1024, 2) . " MB de vazamento\n";
echo "  Economia obtida:    " . number_format($economia / 1024 / 1024, 2) . " MB\n\n";

if ($leakArray > 0) {
    $percentual = (($leakArray - $leakWeak) / $leakArray) * 100;
    echo "  Reducao de leak:    " . number_format($percentual, 1) . "%\n";
}

echo "\nIMPACTO EM PRODUCAO:\n";
echo str_repeat("-", 75) . "\n\n";
echo "Em um worker rodando 24/7 processando milhoes de objetos:\n\n";
echo "  Array tradicional:\n";
echo "    - Acumula entradas orfas indefinidamente\n";
echo "    - Memoria cresce ate atingir limite (Out of Memory)\n";
echo "    - Necessita restart periodico do worker\n";
echo "    - Pode causar downtime e perda de jobs\n\n";
echo "  WeakMap:\n";
echo "    - Libera automaticamente quando objetos saem de escopo\n";
echo "    - Memoria permanece estavel ao longo do tempo\n";
echo "    - Worker pode rodar indefinidamente sem restart\n";
echo "    - Performance previsivel e confiavel\n";
echo "\nCASOS DE USO REAIS:\n";
echo str_repeat("-", 75) . "\n\n";

echo "1. Cache de Metadados em ORMs\n";
echo "   Exemplo: Doctrine, Eloquent\n";
echo "   Uso: Armazenar computed fields, relations cache, etc\n\n";

echo "2. Pool de Conexoes com Contexto\n";
echo "   Exemplo: Database connections, HTTP clients\n";
echo "   Uso: Associar configuracoes/estado a instancias de conexao\n\n";

echo "3. Event Listeners com Estado\n";
echo "   Exemplo: Observers, event subscribers\n";
echo "   Uso: Manter estado especifico por entidade observada\n\n";

echo "4. Decorators e Proxies Dinamicos\n";
echo "   Exemplo: Lazy loading proxies, caching proxies\n";
echo "   Uso: Armazenar metadata do proxy sem vazar memoria\n\n";

echo "5. Memoization de Metodos\n";
echo "   Exemplo: Cache de resultados de metodos caros\n";
echo "   Uso: Guardar resultado por instancia sem impedir GC\n\n";

echo "=================================================\n";
echo "     FIM DO BENCHMARK - WeakMap\n";
echo "=================================================\n";
