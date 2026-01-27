# 💡 Brainstorm: Expansão do Pipeline de ETL e Observabilidade

O projeto base já resolve a geração e o profiling básico. Agora o foco é o "mundo real": como consumir esses dados sem explodir a máquina e como instrumentar o código sem depender de ferramentas de terceiro que "escondem" o gargalo.

## 1. Evolução do Fluxo: O Pipeline de ETL "No Metal"

Para o livro, não quero um ETL que usa bibliotecas prontas. Quero mostrar como o PHP se comporta ao manipular 1GB+ de CSV usando engenharia de baixo nível.

### `src/etl_binario.php` (Transformação de CSV para Binário)

A ideia aqui é provar que CSV é um formato "burro" para alta performance.

* **O que faz:** Lê o CSV denormalizado de 20 colunas.
* **A Técnica:** Usa **Generators** para não carregar o arquivo na RAM e a função `pack()` para converter os dados em uma estrutura binária compacta e fixa.
* **O Ganho:** Mostrar a diferença de velocidade entre dar um `str_getcsv` e ler um binário com `unpack()` (offset fixo vs. parsing de string).

### `src/processador_concorrente.php` (Fibers + Sockets)

Uso prático de concorrência cooperativa (Fibers) no ETL.

* **Cenário:** Durante a importação, precisamos validar o `cliente_estado` ou buscar uma cotação de frete em um "mock" de API.
* **Implementação:** Usar **Fibers** para despachar múltiplas requisições de rede (sockets não-bloqueantes) enquanto o ponteiro do arquivo continua avançando.
* **Fio condutor:** Evitar que o script fique em *IDLE* esperando o I/O da rede.

---

## 2. Novas Ferramentas (Tools) de Diagnóstico

### `tools/top_memory.php` (Monitor de Pressão em Tempo Real)

Um script "watchdog" para rodar em paralelo.

* **Funcionamento:** Ele monitora o PID do processo de ETL e reporta via CLI o `memory_get_usage(true)` em intervalos curtos.
* **Trigger de Emergência:** Se o consumo subir muito rápido (detecção de leak), ele envia um sinal `SIGUSR1` para o PHP realizar um dump de variáveis no log antes de morrer.

### `tools/opcode_viewer.php` (A Arqueologia do Código)

Muitas vezes o gargalo é o overhead de uma função nativa versus outra.

* **Ideia:** Criar um script que invoca o `opcache_compile_file` e formata a saída para o terminal.
* **Utilidade:** Comparar visualmente como o interpretador entende um `foreach` simples vs um `array_map` complexo.

---

## 3. Ideias de Scripts para `src/` (Snippets de Performance)

* **`src/bitwise_flags.php`:** Converter as 20 colunas de status e métodos de pagamento em um único campo inteiro de 8 bits (Bitwise).
* **`src/shm_cache.php`:** Carregar a tabela de `produto_id` e `preco` em Memória Compartilhada (`shmop`) para que múltiplos scripts de ETL consultem os preços sem re-alocar arrays em cada processo.
