# iot/publisher/Dockerfile -- capteur simule (mosquitto_pub).
FROM eclipse-mosquitto:2.0@sha256:212f89e1eaeb2c322d6441b64396e3346026674db8fa9c27beac293405c32b3c
COPY iot/publisher/publish.sh /publish.sh
ENTRYPOINT ["/bin/sh", "/publish.sh"]
