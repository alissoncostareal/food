#!/bin/sh
# Roda o script de inicialização original da imagem
/start.sh & 

# Espera alguns segundos para o Nginx ser gerado pelo start.sh original
sleep 3

# Sobrescreve a configuração problemática
echo "server { listen 80; location / { root /usr/share/nginx/html; index index.html; try_files \$uri \$uri/ /index.html; } }" > /etc/nginx/conf.d/default.conf

# Reinicia o Nginx para aplicar a nova config
nginx -s reload

# Mantém o container vivo
wait