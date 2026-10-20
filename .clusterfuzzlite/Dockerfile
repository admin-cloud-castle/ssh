# ClusterFuzzLite — заглушка для OpenSSF Scorecard (Fuzzing).
# PHP-пакеты не входят в OSS-Fuzz; конфиг фиксирует намерение fuzz-ready CI.
FROM ghcr.io/google/clusterfuzzlite/build-image:latest
WORKDIR /src
# Только манифест — без рекурсивного COPY всего дерева (секреты/.git).
COPY composer.json /src/composer.json
USER 65534:65534
