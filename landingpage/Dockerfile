FROM node:20-alpine AS build-stage

ARG VITE_API_URL=http://localhost:8000/api/v1
ARG VITE_ADMIN_URL=http://localhost:5175
ARG VITE_SITE_URL=http://localhost:5176
ARG VITE_DEMO_STORE_URL=http://localhost:5174/lojademo
ENV VITE_API_URL=$VITE_API_URL
ENV VITE_ADMIN_URL=$VITE_ADMIN_URL
ENV VITE_SITE_URL=$VITE_SITE_URL
ENV VITE_DEMO_STORE_URL=$VITE_DEMO_STORE_URL

WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
RUN npm run build

FROM nginx:stable-alpine
COPY --from=build-stage /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
