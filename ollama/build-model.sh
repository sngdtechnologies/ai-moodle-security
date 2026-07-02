#!/bin/bash
# Construit le modele durci tuteur-secure (N2) DANS le conteneur ollama.
# Usage (controleur) : docker compose exec ollama sh /models/build-model.sh
# HOME=/home/ollama dans le conteneur -> OLLAMA_MODELS resolu par defaut.
set -e
echo "[build-model] pull du modele de base phi3:mini..."
ollama pull phi3:mini
# Epinglage par CONTENU (these 5.4) : on reprend le FROM <blob sha256> ET le
# TEMPLATE de chat du modele de base (via 'ollama show'), puis on ajoute notre
# PARAMETER + SYSTEM durci en dernier (il ecrase un eventuel SYSTEM de base).
# Ainsi le modele est fige sur le blob (verifie au chargement) sans perdre le
# gabarit de conversation, condition d'un modele fonctionnel.
ollama show phi3:mini --modelfile | grep -v '^#' > /tmp/base.modelfile
{
  cat /tmp/base.modelfile
  echo 'PARAMETER temperature 0.3'
  awk '/^SYSTEM/{f=1} f' /models/Modelfile
} > /tmp/Modelfile.pinned
echo "[build-model] FROM epingle par blob :"
grep '^FROM' /tmp/Modelfile.pinned
echo "[build-model] creation du modele durci tuteur-secure..."
ollama create tuteur-secure -f /tmp/Modelfile.pinned
echo "[build-model] modeles disponibles :"
ollama list
