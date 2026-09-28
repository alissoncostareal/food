#!/bin/bash
# Atualiza o PartiuMenu na VPS a partir do branch main.
# Chamado pelo GitHub Actions depois que o CI passa.
set -euo pipefail

LOCK=/root/platform/deploy.lock
exec 9>"$LOCK"
if ! flock -w 1800 9; then
  echo "Outro deploy ainda está rodando."
  exit 1
fi

REPO=/root/platform/repo
BRANCH=main
LOG=/root/platform/deploy.log
mkdir -p /root/platform

exec > >(tee -a "$LOG") 2>&1
echo "===== DEPLOY $(date -u +%Y-%m-%dT%H:%M:%SZ) ====="

if [ ! -d "$REPO/.git" ]; then
  git clone --branch "$BRANCH" --depth 1 https://github.com/alissoncostareal/food.git "$REPO"
fi

cd "$REPO"
git fetch --depth 1 origin "$BRANCH"
git checkout "$BRANCH"
git reset --hard "origin/$BRANCH"
echo "COMMIT $(git rev-parse --short HEAD)"

for f in backend/Dockerfile admin-dashboard/Dockerfile customer-app/Dockerfile.prod landingpage/Dockerfile; do
  if [ ! -f "$f" ]; then
    echo "Falta $f em origin/$BRANCH. Nada foi publicado."
    exit 1
  fi
done

API=https://api.partiumenu.com.br
ADMIN=https://admin.partiumenu.com.br
APP=https://app.partiumenu.com.br
SITE=https://partiumenu.com.br

docker build -t partiumenu-api:latest -f backend/Dockerfile backend
docker build -t partiumenu-admin:latest \
  --build-arg VITE_API_URL="${API}/api/v1" \
  -f admin-dashboard/Dockerfile admin-dashboard
docker build -t partiumenu-customer:latest \
  --build-arg VITE_API_URL="${API}/api/v1" \
  -f customer-app/Dockerfile.prod customer-app
docker build -t partiumenu-landing:latest \
  --build-arg VITE_API_URL="${API}/api/v1" \
  --build-arg VITE_ADMIN_URL="${ADMIN}" \
  --build-arg VITE_SITE_URL="${SITE}" \
  --build-arg VITE_DEMO_STORE_URL="${APP}/lojademo" \
  -f landingpage/Dockerfile landingpage

docker save \
  partiumenu-api:latest \
  partiumenu-admin:latest \
  partiumenu-customer:latest \
  partiumenu-landing:latest \
  | k3s ctr images import -

kubectl -n partiumenu rollout restart \
  deploy/partiumenu-api \
  deploy/partiumenu-worker \
  deploy/partiumenu-admin \
  deploy/partiumenu-customer \
  deploy/partiumenu-landing \
  deploy/kafka-ifood \
  deploy/kafka-orders \
  deploy/kafka-whatsapp-inbound \
  deploy/kafka-whatsapp-outbound

kubectl -n partiumenu rollout status deploy/partiumenu-api --timeout=240s
echo "DEPLOY_OK $(git rev-parse --short HEAD)"
