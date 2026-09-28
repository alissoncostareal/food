#!/bin/sh
set +e

log() {
  echo "[kafka-worker] $1 $(date -u +%Y-%m-%dT%H:%M:%SZ)"
}

if [ -z "$APP_KEY" ]; then
  log "ERROR: APP_KEY não configurada."
  exit 1
fi

if [ -z "$DATABASE_URL" ] && [ "$DB_CONNECTION" != "pgsql" ]; then
  log "ERROR: Postgres não configurado no kafka worker."
  exit 1
fi

php artisan optimize:clear || log "WARN: optimize:clear falhou"
php artisan config:cache || log "WARN: config:cache falhou"

TOPICS=${KAFKA_TOPICS:-"whatsapp.inbound.messages"}
CONSUMER=${KAFKA_CONSUMER_CLASS:-"App\\Kafka\\Consumers\\WhatsappInboundKafkaHandler"}
GROUP_ID=${KAFKA_CONSUMER_GROUP_ID:-"partiumenu-ai-consumer"}

log "online pid=$$ — escutando tópicos [${TOPICS}] no grupo [${GROUP_ID}]"

while true; do
  log "kafka:consume iniciando..."
  php -d memory_limit=256M artisan kafka:consume \
    --topics="${TOPICS}" \
    --consumer="${CONSUMER}" \
    --groupId="${GROUP_ID}" \
    --maxTime=3600 \
    --maxMessage=1000
  code=$?
  log "kafka:consume saiu com código ${code}; reiniciando em 3s"
  sleep 3
done
