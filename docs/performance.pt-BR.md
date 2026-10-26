# Desempenho e limites

[English](performance.md) · **Português (Brasil)**

Quanto um processo aguenta, o que ele precisa para chegar lá, e como medir na sua própria máquina.

## Do que um processo precisa

- **A extensão `event` acima de cerca de 1.000 conexões.** Sem ela, o Workerman usa o `select()` do PHP, que atende no máximo 1.024 descritores por processo: além disso, o Workerman avisa e as conexões novas falham. Com o `event` instalado, o Workerman o escolhe sozinho:

```sh
pecl install event
# o event precisa carregar depois do sockets: o nome do ini o deixa por último
echo "extension=event.so" > "$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')/zz-event.ini"
```

- **Um `memory_limit` que caiba as conexões.** Cada conexão WebSocket ocupa cerca de 30 KB, então os 128 MB padrão do CLI comportam umas 4.000. Para 10.000, dê 512 MB ou mais (`php -d memory_limit=512M server.php start`).
- **Descritores de arquivo suficientes:** `ulimit -n` acima do número de conexões, mais alguns para o socket de escuta e arquivos.

## Resultados

Um processo, clientes WebSocket do `socket.io-client` oficial, medidos com `tests/Load/run.sh`:

| Clientes | Event loop | Memória por conexão | RSS total | Conectar todos | Ecos por segundo (p50 / p99) | Entregas de broadcast por segundo |
|---|---|---|---|---|---|---|
| 900 | `select()` | 27 KB | 38 MB | 0,24 s | 53.000 (16 / 47 ms) | 250.000 |
| 1.000 | `event` | 29 KB | 43 MB | 0,21 s | 59.000 (15 / 33 ms) | 290.000 |
| 5.000 | `event` | 30 KB | 156 MB | 0,80 s | 65.000 (69 / 123 ms) | 220.000 |
| 10.000 | `event` | 30 KB | 298 MB | 1,38 s | 55.000 (142 / 298 ms) | 200.000 |

- **A memória** cresce de forma linear: cerca de 30 KB por conexão, tanto no heap do PHP quanto na memória residente do processo.
- **Os ecos** são acknowledgements: cada cliente envia 20, um após o outro, todos os clientes ao mesmo tempo. A latência é sob essa carga total, não a de um servidor ocioso.
- **Os broadcasts** são 50 eventos de 100 bytes para todos os clientes. O tempo para emiti-los é quase o tempo até todos serem entregues, então o limite é a CPU de um processo: mais processos, com `ChannelAdapter` ou `RedisAdapter`, multiplicam isso (veja [Vários processos](multiple-processes.pt-BR.md)).

Medido num Apple M4 Pro, no Docker Desktop (14 CPUs, 8 GB), com PHP 8.1 e Node.js 24 na mesma máquina. Os seus números vão ser outros; a forma, não.

## Medindo na sua máquina

```sh
tests/Load/run.sh                              # 500 clientes no select()
LOOP=event CLIENTS=10000 tests/Load/run.sh     # a extensão event, 10.000 clientes
```

`CLIENTS`, `ROUNDS` (ecos por cliente), `MESSAGES` (broadcasts) e `SIZE` (bytes por broadcast) ajustam a rodada. Cada rodada imprime uma linha de JSON e um resumo.
