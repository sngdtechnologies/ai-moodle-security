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

## Accès Moodle (via tunnel SSH depuis ton poste)
```bash
ssh -L 8080:localhost:8080 ubuntu@<IP_EC2>
# puis ouvrir http://localhost:8080
```
