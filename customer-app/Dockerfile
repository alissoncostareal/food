# Estágio 1: Build
FROM node:20-alpine AS build-stage
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
RUN npm run build

# Estágio 2: Produção (Nginx)
FROM nginx:stable-alpine as production-stage
# Copia os arquivos compilados do estágio anterior para o diretório do Nginx
COPY --from=build-stage /app/dist /usr/share/nginx/html
# Copia uma configuração customizada do Nginx
COPY nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]