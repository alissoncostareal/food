# Estágio 1: Build
FROM node:20-alpine AS build-stage

ARG VITE_API_URL=http://localhost:8000/api/v1
ENV VITE_API_URL=$VITE_API_URL

WORKDIR /app

# Copia apenas arquivos de dependência para otimizar o cache do Docker
COPY package*.json ./
RUN npm install

# Copia o restante do código e realiza o build
COPY . .
RUN npm run build

# Estágio 2: Produção (Nginx)
FROM nginx:stable-alpine as production-stage

# Copia os arquivos estáticos gerados no estágio anterior
COPY --from=build-stage /app/dist /usr/share/nginx/html

# Copia a configuração do Nginx para tratar as rotas (SPA)
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Expõe a porta 80
EXPOSE 80

# Inicia o servidor Nginx em primeiro plano
CMD ["nginx", "-g", "daemon off;"]