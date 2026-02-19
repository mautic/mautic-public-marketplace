#!/usr/bin/env bash
set -euo pipefail

APP_NAME="${APP_NAME:-marketplace}"
IMAGE_TAG="${IMAGE_TAG:-}"
IMAGE_TAR="${IMAGE_TAR:-/tmp/${APP_NAME}.tar}"
CONTAINER_NAME="${CONTAINER_NAME:-${APP_NAME}}"
APP_PORT="${APP_PORT:-8080}"
APP_ENV_FILE="${APP_ENV_FILE:-/etc/marketplace/app.env}"
DEPLOY_ENV_FILE="${DEPLOY_ENV_FILE:-/etc/marketplace/deploy.env}"
NGINX_SITE_NAME="${NGINX_SITE_NAME:-marketplace}"

if [[ -f "${DEPLOY_ENV_FILE}" ]]; then
  # shellcheck disable=SC1090
  source "${DEPLOY_ENV_FILE}"
fi

if [[ -z "${IMAGE_TAG}" ]]; then
  echo "IMAGE_TAG is required"
  exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required on the droplet"
  exit 1
fi

if [[ -f "${IMAGE_TAR}" ]]; then
  docker load -i "${IMAGE_TAR}"
fi

if docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
  docker rm -f "${CONTAINER_NAME}"
fi

if [[ -f "${APP_ENV_FILE}" ]]; then
  APP_ENV_FILE_ARG=("--env-file" "${APP_ENV_FILE}")
else
  APP_ENV_FILE_ARG=()
fi

# Run the container on localhost and let Nginx handle public traffic.
docker run -d \
  --name "${CONTAINER_NAME}" \
  --restart unless-stopped \
  -p "127.0.0.1:${APP_PORT}:80" \
  "${APP_ENV_FILE_ARG[@]}" \
  "${IMAGE_TAG}"

PROD_DOMAIN="${APP_DOMAIN:-}"
PROD_PORT="${APP_DOMAIN_PORT:-8080}"
STAGING_DOMAIN="${STAGING_DOMAIN:-}"
STAGING_PORT="${STAGING_DOMAIN_PORT:-8081}"

if [[ -n "${PROD_DOMAIN}" || -n "${STAGING_DOMAIN}" ]]; then
  if ! command -v nginx >/dev/null 2>&1; then
    apt-get update
    apt-get install -y --no-install-recommends nginx
  fi

  if ! command -v certbot >/dev/null 2>&1; then
    apt-get update
    apt-get install -y --no-install-recommends certbot python3-certbot-nginx
  fi

  {
    if [[ -n "${PROD_DOMAIN}" ]]; then
      cat <<NGINX_CONF
server {
    listen 80;
    server_name ${PROD_DOMAIN};

    location / {
        proxy_pass http://127.0.0.1:${PROD_PORT};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX_CONF
    fi

    if [[ -n "${STAGING_DOMAIN}" ]]; then
      cat <<NGINX_CONF
server {
    listen 80;
    server_name ${STAGING_DOMAIN};

    location / {
        proxy_pass http://127.0.0.1:${STAGING_PORT};
        add_header X-Robots-Tag "noindex, nofollow, noarchive, nosnippet" always;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX_CONF
    fi
  } > /etc/nginx/sites-available/${NGINX_SITE_NAME}.conf

  ln -sf "/etc/nginx/sites-available/${NGINX_SITE_NAME}.conf" "/etc/nginx/sites-enabled/${NGINX_SITE_NAME}.conf"
  rm -f /etc/nginx/sites-enabled/default

  nginx -t
  systemctl reload nginx

  if [[ -n "${LETSENCRYPT_EMAIL:-}" ]]; then
    if [[ -n "${PROD_DOMAIN}" ]]; then
      if [[ ! -f "/etc/letsencrypt/live/${PROD_DOMAIN}/fullchain.pem" ]]; then
        certbot --nginx \
          --non-interactive \
          --agree-tos \
          --email "${LETSENCRYPT_EMAIL}" \
          -d "${PROD_DOMAIN}"
      fi
    fi

    if [[ -n "${STAGING_DOMAIN}" ]]; then
      if [[ ! -f "/etc/letsencrypt/live/${STAGING_DOMAIN}/fullchain.pem" ]]; then
        certbot --nginx \
          --non-interactive \
          --agree-tos \
          --email "${LETSENCRYPT_EMAIL}" \
          -d "${STAGING_DOMAIN}"
      fi
    fi
  else
    echo "LETSENCRYPT_EMAIL is not set; skipping certificate issuance"
  fi
else
  echo "No domains set; skipping Nginx/Certbot setup"
fi
