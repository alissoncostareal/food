# Estágio 1: Build
FROM node:20-alpine AS build-stage

# Força o reconhecimento do NPM (Garantia extra)
RUN node -v && npm -v

WORKDIR /app

COPY package*.json ./

# Se o npm install falhar aqui, vamos usar o --no-cache
RUN npm install

COPY . .

# Compila o projeto para produção (gera a pasta dist)
RUN npm run build

# Estágio 2: Produção (Nginx)
FROM nginx:stable-alpine as production-stage

# Copia os arquivos compilados do estágio anterior para o Nginx
COPY --from=build-stage /app/dist /usr/share/nginx/html

# Copia uma configuração customizada do Nginx para suportar o Vue Router
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]