# Desenvolvimento

[English](development.md) · **Português (Brasil)**

Tudo roda no Docker; não é preciso ter PHP nem Composer na máquina.

```sh
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php composer lint      # phpcs (PSR-12) e PHPStan (nível max)
tests/Compliance/engine-io.sh                  # a suíte oficial do Engine.IO v4
tests/Compliance/socket-io.sh                  # a suíte oficial do Socket.IO v5
tests/Integration/run.sh                       # o socket.io-client oficial contra este servidor
tests/Integration/run.sh multi-process         # o mesmo com dois processos e um servidor de canal
tests/Integration/run.sh multi-process-redis   # os mesmos dois processos, ligados pelo Redis com TLS
tests/Integration/run.sh examples              # todos os exemplos, cada um contra o seu teste
tests/Load/run.sh                              # o teste de carga; veja docs/performance.pt-BR.md
LOOP=swoole tests/Integration/run.sh           # qualquer script acima em outro event loop
```

`LOOP=select`, `event` ou `swoole` roda o servidor dos scripts de conformidade, integração e carga nesse event loop do Workerman, na imagem do `tests/Load/Dockerfile`, que tem as extensões `event` e `swoole`. O CI também roda as suítes e os testes de integração no Swoole.

## Varredura de segredos

O [gitleaks](https://github.com/gitleaks/gitleaks) roda no Docker e bloqueia commits que adicionam segredos. Ative o hook uma vez por clone:

```sh
git config core.hooksPath .githooks
```

O Docker precisa estar rodando para commitar. O CI também varre o histórico inteiro em todo pull request.
