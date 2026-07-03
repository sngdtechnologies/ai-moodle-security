# eval/garak/Dockerfile -- image garak (scanner de vulnerabilites LLM de NVIDIA).
# Construite cote hote (avec Internet) ; executee attachee au reseau backend
# (interne) pour joindre la passerelle ollama-gate.
FROM python:3.12-slim
RUN pip install --no-cache-dir garak
WORKDIR /work
ENTRYPOINT ["garak"]
