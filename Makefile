SHELL := /bin/zsh
HERD := PATH="$$HOME/Library/Application Support/Herd/bin:$$PATH"

.PHONY: up down fresh api web test e2e openapi

up: ## Démarre l'infrastructure dev (postgres, redis, meilisearch, minio, mailpit)
	docker compose -f docker/dev/docker-compose.yml up -d

down:
	docker compose -f docker/dev/docker-compose.yml down

fresh: up ## Base neuve + référentiels + démo
	cd apps/api && $(HERD) php artisan migrate:fresh --force && $(HERD) php artisan db:seed --force && $(HERD) php artisan db:seed --class=DemoTenantSeeder --force

api: ## Serveur API local
	cd apps/api && $(HERD) php artisan serve --port=8088

web: ## Frontend dev
	pnpm --filter @silaris/web dev

worker: ## Worker queues (redis)
	cd apps/api && $(HERD) php artisan queue:work redis --queue=odoo,tracking,notifications,default

test: ## Suite backend complète
	cd apps/api && $(HERD) php -d memory_limit=512M artisan test

e2e: ## Tests navigateur
	cd apps/web && npx playwright test

openapi: ## Régénère spec + client TS
	cd apps/api && $(HERD) php artisan scramble:export --path=../../packages/api-client/openapi.json
	pnpm --filter @silaris/api-client generate
