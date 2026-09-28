# 🚀 PartiuMenu — Guia de Deploy no Kubernetes (K8s)

Este diretório contém os manifestos prontos para produção do ecossistema PartiuMenu no Kubernetes.

---

## 📦 Arquitetura dos Manifestos

| Manifesto | Descrição |
| :--- | :--- |
| [`namespace.yaml`](file:///Users/alissoncosta/dev/food/k8s/namespace.yaml) | Cria o namespace isolado `partiumenu`. |
| [`configmap.yaml`](file:///Users/alissoncosta/dev/food/k8s/configmap.yaml) | Variáveis de ambiente não sensíveis (rotas, configurações de fila, Kafka e IA). |
| [`secrets.example.yaml`](file:///Users/alissoncosta/dev/food/k8s/secrets.example.yaml) | Credenciais protegidas (Banco Postgres, chaves Gemini/OpenAI, Pagar.me, S3/R2). |
| [`api-deployment.yaml`](file:///Users/alissoncosta/dev/food/k8s/api-deployment.yaml) | API Laravel com Service, Probes (`liveness`/`readiness`) e HPA (2 a 10 réplicas). |
| [`worker-deployment.yaml`](file:///Users/alissoncosta/dev/food/k8s/worker-deployment.yaml) | Worker de filas convencionais (`php artisan queue:work`). |
| [`kafka-consumer-deployment.yaml`](file:///Users/alissoncosta/dev/food/k8s/kafka-consumer-deployment.yaml) | Consumidor Kafka com IA para mensagens de WhatsApp e pedidos. |
| [`scheduler-cronjob.yaml`](file:///Users/alissoncosta/dev/food/k8s/scheduler-cronjob.yaml) | CronJob nativo que executa `php artisan schedule:run` a cada 1 minuto com custo zero de ociosidade. |
| [`ingress.yaml`](file:///Users/alissoncosta/dev/food/k8s/ingress.yaml) | Ingress NGINX com emissão automática de certificado SSL via cert-manager. |
| [`keda-scaledobject.yaml`](file:///Users/alissoncosta/dev/food/k8s/keda-scaledobject.yaml) | Autoscaling baseado no lag de mensagens do Kafka (KEDA). |

---

## 🛠️ Passo a Passo para Deploy

### 1. Criar Secrets Reais
Copie o exemplo e preencha com as credenciais de produção:
```bash
cp k8s/secrets.example.yaml k8s/secrets.yaml
# Edite k8s/secrets.yaml com suas chaves
kubectl apply -f k8s/namespace.yaml
kubectl apply -f k8s/secrets.yaml
kubectl apply -f k8s/configmap.yaml
```

### 2. Construir e Publicar as Imagens Docker
```bash
# API
docker build -t seu-registry/partiumenu-api:latest -f backend/Dockerfile backend/
docker push seu-registry/partiumenu-api:latest

# Worker
docker build -t seu-registry/partiumenu-worker:latest -f backend/Dockerfile.worker backend/
docker push seu-registry/partiumenu-worker:latest
```

### 3. Aplicar os Deployments e CronJobs
```bash
kubectl apply -f k8s/api-deployment.yaml
kubectl apply -f k8s/worker-deployment.yaml
kubectl apply -f k8s/kafka-consumer-deployment.yaml
kubectl apply -f k8s/scheduler-cronjob.yaml
kubectl apply -f k8s/ingress.yaml
```

### 4. (Opcional) Habilitar Autoscaling por Lag do Kafka (KEDA)
Se o cluster tiver o KEDA instalado:
```bash
kubectl apply -f k8s/keda-scaledobject.yaml
```
