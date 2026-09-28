#!/bin/bash
set -euo pipefail
cd /root/platform/src
API=https://api.partiumenu.com.br
ADMIN=https://admin.partiumenu.com.br
APP=https://app.partiumenu.com.br
LANDING=https://partiumenu.com.br

echo "BUILD api $(date -u +%H:%M:%S)"
docker build -t partiumenu-api:latest -f backend/Dockerfile backend

echo "BUILD admin $(date -u +%H:%M:%S)"
docker build -t partiumenu-admin:latest \
  --build-arg VITE_API_URL="${API}/api/v1" \
  -f admin-dashboard/Dockerfile admin-dashboard

echo "BUILD customer $(date -u +%H:%M:%S)"
docker build -t partiumenu-customer:latest \
  --build-arg VITE_API_URL="${API}/api/v1" \
  -f customer-app/Dockerfile.prod customer-app

echo "BUILD landing $(date -u +%H:%M:%S)"
docker build -t partiumenu-landing:latest \
  --build-arg VITE_API_URL="${API}/api/v1" \
  --build-arg VITE_ADMIN_URL="${ADMIN}" \
  --build-arg VITE_SITE_URL="${LANDING}" \
  --build-arg VITE_DEMO_STORE_URL="${APP}/lojademo" \
  -f landingpage/Dockerfile landingpage

echo "IMPORT $(date -u +%H:%M:%S)"
docker save partiumenu-api:latest partiumenu-admin:latest partiumenu-customer:latest partiumenu-landing:latest | k3s ctr images import -
echo "DONE $(date -u +%H:%M:%S)"
