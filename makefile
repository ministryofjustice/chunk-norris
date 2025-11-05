build:
	docker build -t chunk-norris:latest .

run:
	docker compose up --build

deploy:
	kubectl apply -f deploy.yaml

trigger:
	kubectl create job --from=cronjob/chunk-norris-cron chunk-norris-cron-$$(date +%s) -n website-builder-assistant-dev


