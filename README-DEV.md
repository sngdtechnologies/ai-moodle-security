# Développement — prototype Moodle-Ollama sécurisé

## Préparation (une fois)
```bash
cp .env.example .env
cp secrets/db_password.txt.example        secrets/db_password.txt
cp secrets/moodle_admin_pass.txt.example  secrets/moodle_admin_pass.txt
cp secrets/ollama_token.txt.example       secrets/ollama_token.txt
# éditer chaque secrets/*.txt avec des valeurs réelles
```

## Lancer (phase 1)
```bash
docker compose up -d --build
```

## Tirer le modèle (obligatoire au premier déploiement)
Le service `ollama` ne tire pas le modèle automatiquement en phase 1. Sur un déploiement neuf
(volume `ollama_models` vide), le tuteur renverrait « indisponible » (Ollama répond 404
`model not found`). Tirer le modèle une fois — il persiste ensuite dans le volume :
```bash
docker compose exec ollama ollama pull phi3:mini
```
> Le provisionnement automatisé et épinglé du modèle (Modelfile durci `tuteur-secure`,
> `build-model.sh`, puis isolation réseau) est traité en phases 2 et 3.

## Accès Moodle (via tunnel SSH depuis ton poste)
```bash
ssh -L 8080:localhost:8080 ubuntu@<IP_EC2>
# puis ouvrir http://localhost:8080
```
