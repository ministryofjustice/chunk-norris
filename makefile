build:
	docker build -t chunk-norris:latest .

run:
	docker compose up 

down:
	docker compose down --remove-orphans

deploy:
	kubectl apply -f deploy.yaml

debug:
	kubectl apply -f debug.yaml

trigger:
	kubectl create job --from=cronjob/chunk-norris-cron chunk-norris-cron-$$(date +%s) -n website-builder-assistant-dev


