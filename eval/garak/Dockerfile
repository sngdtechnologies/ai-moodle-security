# eval/garak/Dockerfile -- image garak (scanner de vulnerabilites LLM de NVIDIA).
# Construite cote hote (avec Internet) ; executee attachee au reseau backend
# (interne) pour joindre la passerelle ollama-gate.
FROM python:3.12-slim
# torch CPU-only d'abord (satisfait la dependance de garak sans tirer les libs CUDA,
# inutiles sur cette instance sans GPU et trop volumineuses).
RUN pip install --no-cache-dir torch --index-url https://download.pytorch.org/whl/cpu \
 && pip install --no-cache-dir garak
WORKDIR /work
ENTRYPOINT ["garak"]
