#!/bin/bash
# Azure App Service custom startup step (blessed PHP Linux image). Points
# nginx's docroot at public/ and adds the clean-URL fallback for /api/*
# that public/api/.htaccess provides under Apache (local dev) but nginx
# has no equivalent for without an explicit location block. See
# config/azure-nginx-default.conf for the actual config being installed.
#
# On a cold container, this script can run before the base image has
# finished writing its own default nginx config file - seen directly via
# log streaming as "sed: can't read /etc/nginx/sites-available/default"
# on some restarts but not others. Wait for it to exist first instead of
# assuming it's already there.
NGINX_CONF=/etc/nginx/sites-available/default
for i in $(seq 1 30); do
  [ -f "$NGINX_CONF" ] && break
  sleep 0.5
done

if [ -f "$NGINX_CONF" ]; then
  cp /home/site/wwwroot/config/azure-nginx-default.conf "$NGINX_CONF"
  nginx -s reload 2>&1 || nginx -t 2>&1
fi
