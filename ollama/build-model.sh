#!/bin/bash
# Construit le modele durci tuteur-secure (N2) DANS le conteneur ollama.
# Usage (controleur) : docker compose exec ollama sh /models/build-model.sh
set -e
echo "[build-model] pull du modele de base phi3:mini..."
ollama pull phi3:mini
echo "[build-model] creation du modele durci tuteur-secure..."
ollama create tuteur-secure -f /models/Modelfile
echo "[build-model] modeles disponibles :"
ollama list
